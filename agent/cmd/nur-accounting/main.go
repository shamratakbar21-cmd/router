// nur-accounting is a root-only durable handoff and replay worker for Accel-PPP.
package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net"
	"nurrouteros/agent/internal/accounting"
	"nurrouteros/agent/internal/bras"
	"os"
	"sync"
	"syscall"
	"time"
)

const directory = "/etc/nurrouter/accounting"

func die(e error) { fmt.Fprintln(os.Stderr, e); os.Exit(1) }
func main() {
	if os.Geteuid() != 0 || len(os.Args) != 2 {
		die(fmt.Errorf("root required; usage: nur-accounting put|status|check|run"))
	}
	q := accounting.New(directory)
	switch os.Args[1] {
	case "put":
		raw, e := io.ReadAll(io.LimitReader(os.Stdin, 4097))
		if e != nil {
			die(e)
		}
		kind, _, e := accounting.Identity(raw)
		if e != nil {
			die(e)
		}
		source, e := accounting.NASAddress(raw)
		if e != nil {
			die(e)
		}
		address, secret := "", ""
		// Existing sessions retain their original destination and secret even when
		// configuration is disabled/replaced before graceful service shutdown.
		if kind == 1 {
			cfg, e := os.ReadFile("/etc/nurrouter/bras.json")
			if e != nil {
				die(e)
			}
			c, e := bras.Decode(cfg)
			if e != nil {
				die(e)
			}
			if !c.Enabled || c.Auth != "radius" || c.Radius.NAS != source {
				die(fmt.Errorf("RADIUS Start configuration mismatch"))
			}
			address, secret = net.JoinHostPort(c.Radius.Address, "1813"), c.Radius.Secret
		}
		if e = q.Put(raw, address, source, secret); e != nil {
			die(e)
		}

	case "status", "check":
		s, e := q.Status()
		if e != nil {
			die(e)
		}
		if os.Args[1] == "check" && s.Restricted {
			die(fmt.Errorf("accounting backpressure: admission restricted"))
		}
		json.NewEncoder(os.Stdout).Encode(s)
	case "run":
		if _, e := q.Status(); e != nil {
			die(e)
		}
		f, e := os.OpenFile(directory+"/worker.lock", os.O_CREATE|os.O_RDWR, 0600)
		if e != nil {
			die(e)
		}
		defer f.Close()
		if e = syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); e != nil {
			die(fmt.Errorf("accounting worker already running"))
		}
		retry := map[string]time.Time{}
		attempts := map[string]int{}
		pace := time.NewTicker(10 * time.Millisecond)
		defer pace.Stop()
		for {
			pending, e := q.Pending()
			if e != nil {
				fmt.Fprintln(os.Stderr, "accounting queue read failed; retained, admission must close")
				time.Sleep(5 * time.Second)
				continue
			}
			var wg sync.WaitGroup
			sem := make(chan struct{}, 8)
			var mu sync.Mutex
			for name, r := range pending {
				mu.Lock()
				wait := time.Now().Before(retry[name])
				mu.Unlock()
				if wait {
					continue
				}
				sem <- struct{}{}
				<-pace.C
				wg.Add(1)
				go func(name string, r accounting.Record) {
					defer wg.Done()
					defer func() { <-sem }()
					id, e := accounting.Deliver(r, time.Now())
					if e == nil {
						e = q.Ack(name, id)
					}
					mu.Lock()
					defer mu.Unlock()
					if e != nil {
						attempts[name]++
						n := attempts[name]
						if n > 6 {
							n = 6
						}
						retry[name] = time.Now().Add(time.Duration(1<<n) * time.Second)
					} else {
						delete(attempts, name)
						delete(retry, name)
					}
				}(name, r)
			}
			wg.Wait()
			for n := range retry {
				if _, ok := pending[n]; !ok {
					delete(retry, n)
					delete(attempts, n)
				}
			}
			time.Sleep(time.Second)
		}
	default:
		die(fmt.Errorf("unsupported accounting operation"))
	}
}
