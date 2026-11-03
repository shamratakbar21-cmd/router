// nur-brasctl is root-only and never exposes arbitrary engine commands over SSH.
package main

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"nurrouteros/agent/internal/accounting"
	"nurrouteros/agent/internal/bras"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

const run = "/var/run/nur-bras"

func fail(e error) { fmt.Fprintln(os.Stderr, e); os.Exit(1) }
func command(cmd string) (string, error) {
	key, e := os.ReadFile(run + "/cli-key")
	if e != nil {
		return "", e
	}
	c, e := net.DialTimeout("tcp", "127.0.0.1:2001", 2*time.Second)
	if e != nil {
		return "", e
	}
	defer c.Close()
	c.SetDeadline(time.Now().Add(3 * time.Second))
	fmt.Fprintf(c, "%s\n%s\nexit\n", strings.TrimSpace(string(key)), cmd)
	b, e := io.ReadAll(io.LimitReader(c, 1024*1024))
	if strings.Contains(string(b), "failed") || strings.Contains(string(b), "already in progress") {
		return "", fmt.Errorf("engine rejected command")
	}
	return string(b), e
}
func main() {
	if os.Geteuid() != 0 {
		fail(fmt.Errorf("root required"))
	}
	if len(os.Args) < 2 || len(os.Args) > 3 {
		fail(fmt.Errorf("usage: nur-brasctl validate|render|observe|reload|restrict"))
	}
	action := os.Args[1]
	if action == "observe" {
		out, e := command("show sessions")
		status := "running"
		if e != nil {
			status = "unavailable"
			out = ""
		}
		interfaces, _ := command("pppoe interface show")
		digest, _ := os.ReadFile(run + "/applied-config.sha256")
		json.NewEncoder(os.Stdout).Encode(map[string]any{"configuration_sha256": strings.TrimSpace(string(digest)), "access_interfaces_table": interfaces, "engine": "accel-ppp", "milestone": "0.5.0-experimental", "state": status, "sessions_table": out, "accounting": accounting.New("/etc/nurrouter/accounting").String()})
		return
	}
	if action == "restrict" {
		_, e := command("shutdown soft")
		if e != nil {
			fail(e)
		}
		return
	}
	configPath := "/etc/nurrouter/bras.json"
	if len(os.Args) == 3 {
		if action != "validate" {
			fail(fmt.Errorf("only validate accepts a path"))
		}
		configPath = os.Args[2]
	}
	data, e := os.ReadFile(configPath)
	if e != nil {
		fail(e)
	}
	c, e := bras.Decode(data)
	if e != nil {
		fail(e)
	}
	if action == "validate" {
		return
	}
	if action != "render" && action != "reload" {
		fail(fmt.Errorf("unsupported operation"))
	}
	if !c.Enabled {
		fail(fmt.Errorf("BRAS disabled"))
	}
	if c.Auth == "radius" {
		s, err := accounting.New("/etc/nurrouter/accounting").Status()
		if err != nil || s.Restricted {
			command("shutdown soft")
			fail(fmt.Errorf("accounting backpressure: new admission restricted"))
		}
	}
	limit := 0
	if _, e := os.Stat("/etc/nurrouter/license.json"); e == nil {
		output, e := exec.Command("/usr/sbin/nur-license-check").Output()
		var checked struct {
			Allow bool `json:"allow_entitlement_mutations"`
		}
		if e != nil || json.Unmarshal(output, &checked) != nil || !checked.Allow {
			command("shutdown soft")
			fail(fmt.Errorf("new subscriber admission restricted by license"))
		}
		var envelope struct {
			Payload struct {
				Entitlements struct {
					Max int `json:"max_pppoe_sessions"`
				} `json:"entitlements"`
			} `json:"payload"`
		}
		b, e := os.ReadFile("/etc/nurrouter/license.json")
		if e != nil || json.Unmarshal(b, &envelope) != nil || envelope.Payload.Entitlements.Max < 0 {
			fail(fmt.Errorf("invalid signed license limit"))
		}
		limit = envelope.Payload.Entitlements.Max
	}
	if e := os.MkdirAll(run, 0700); e != nil {
		fail(e)
	}
	lock, e := os.OpenFile(run+"/render.lock", os.O_CREATE|os.O_RDWR, 0600)
	if e != nil {
		fail(e)
	}
	defer lock.Close()
	if e = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); e != nil {
		fail(fmt.Errorf("BRAS configuration busy"))
	}
	key, e := os.ReadFile(run + "/cli-key")
	if os.IsNotExist(e) {
		b := make([]byte, 32)
		if _, e = rand.Read(b); e != nil {
			fail(e)
		}
		key = []byte(hex.EncodeToString(b))
		if e = os.WriteFile(run+"/cli-key", key, 0600); e != nil {
			fail(e)
		}
	} else if e != nil {
		fail(e)
	}
	conf, secrets, e := c.Render(string(key), limit)
	if e != nil {
		fail(e)
	}
	for n, v := range map[string]string{"accel.conf": conf, "chap-secrets": secrets} {
		p := filepath.Join(run, n)
		if e = os.WriteFile(p+".tmp", []byte(v), 0600); e != nil {
			fail(e)
		}
		if e = os.Rename(p+".tmp", p); e != nil {
			fail(e)
		}
	}
	if action == "reload" {
		_, e = command("shutdown cancel")
		if e != nil {
			fail(e)
		}
		_, e = command("reload")
		if e != nil {
			fail(e)
		}
	}
	sum := sha256.Sum256(data)
	if e = os.WriteFile(run+"/applied-config.sha256", []byte(hex.EncodeToString(sum[:])), 0600); e != nil {
		fail(e)
	}

}
