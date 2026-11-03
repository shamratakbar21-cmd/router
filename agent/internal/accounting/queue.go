// Package accounting implements the appliance's bounded, crash-recoverable outbox.
// Files contain subscriber identifiers and RADIUS secrets: never expose their contents.
package accounting

import (
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

const BankSize = 32768
const Slots = 2048
const HighWater = 1536
const FreeFloor = 64 * 1024 * 1024
const Retention = 7 * 24 * time.Hour

type Event struct {
	ID     uint64
	At     int64
	Packet []byte
	Ack    bool
}
type Record struct {
	Generation           uint64
	Session              string
	Address              string
	Source               string
	Secret               string
	Sequence             uint64
	Start, Interim, Stop *Event
	Coalesced            uint64
}
type Queue struct {
	Dir     string
	Limit   int
	MinFree uint64
	Now     func() time.Time
}
type Status struct {
	Records       int    `json:"records"`
	Pending       int    `json:"pending"`
	Coalesced     uint64 `json:"coalesced"`
	OldestSeconds int64  `json:"oldest_seconds"`
	FreeBytes     uint64 `json:"free_bytes"`
	Restricted    bool   `json:"new_admission_restricted"`
}

func New(dir string) *Queue { return &Queue{dir, HighWater, FreeFloor, time.Now} }
func (q *Queue) lock() (*os.File, error) {
	if e := os.MkdirAll(q.Dir, 0700); e != nil {
		return nil, e
	}
	if e := os.Chmod(q.Dir, 0700); e != nil {
		return nil, e
	}
	f, e := os.OpenFile(filepath.Join(q.Dir, "lock"), os.O_CREATE|os.O_RDWR, 0600)
	if e != nil {
		return nil, e
	}
	if e = syscall.Flock(int(f.Fd()), syscall.LOCK_EX); e != nil {
		f.Close()
		return nil, e
	}
	// A .new file cannot have been acknowledged: publication follows its first fsync.
	entries, err := os.ReadDir(q.Dir)
	if err != nil {
		unlock(f)
		return nil, err
	}
	for _, entry := range entries {
		if strings.HasSuffix(entry.Name(), ".slot.new") {
			if err := os.Remove(filepath.Join(q.Dir, entry.Name())); err != nil {
				unlock(f)
				return nil, err
			}
		}
	}
	return f, nil
}
func unlock(f *os.File) { syscall.Flock(int(f.Fd()), syscall.LOCK_UN); f.Close() }
func (q *Queue) names() ([]string, error) {
	es, e := os.ReadDir(q.Dir)
	if e != nil {
		return nil, e
	}
	var ns []string
	for _, x := range es {
		if strings.HasSuffix(x.Name(), ".slot") {
			ns = append(ns, x.Name())
		}
	}
	return ns, nil
}
func (q *Queue) read(name string) (*Record, error) {
	f, e := os.Open(filepath.Join(q.Dir, name))
	if e != nil {
		return nil, e
	}
	defer f.Close()
	var best *Record
	for bank := 0; bank < 2; bank++ {
		b := make([]byte, BankSize)
		n, e := f.ReadAt(b, int64(bank*BankSize))
		if e != nil && e != io.EOF {
			return nil, e
		}
		if n < 40 {
			continue
		}
		size := int(binary.BigEndian.Uint32(b[4:8]))
		if string(b[:4]) != "NAC1" || size < 1 || size > BankSize-40 || n < size+40 {
			continue
		}
		sum := sha256.Sum256(b[40 : 40+size])
		if string(sum[:]) != string(b[8:40]) {
			continue
		}
		var r Record
		if json.Unmarshal(b[40:40+size], &r) != nil || r.Generation == 0 {
			continue
		}
		if best == nil || r.Generation > best.Generation {
			best = &r
		}
	}
	if best == nil {
		return nil, errors.New("accounting slot corrupt: admission must remain closed")
	}
	return best, nil
}
func (q *Queue) write(name string, r *Record) error {
	r.Generation++
	raw, e := json.Marshal(r)
	if e != nil {
		return e
	}
	if len(raw) > BankSize-40 {
		return errors.New("accounting record exceeds reserved bank")
	}
	b := make([]byte, 40+len(raw))
	copy(b, "NAC1")
	binary.BigEndian.PutUint32(b[4:8], uint32(len(raw)))
	sum := sha256.Sum256(raw)
	copy(b[8:40], sum[:])
	copy(b[40:], raw)
	f, e := os.OpenFile(filepath.Join(q.Dir, name), os.O_RDWR, 0600)
	if e != nil {
		return e
	}
	defer f.Close()
	n, e := f.WriteAt(b, int64((r.Generation%2)*BankSize))
	if e != nil {
		return e
	}
	if n != len(b) {
		return io.ErrShortWrite
	}
	return f.Sync()
}
func (q *Queue) syncDir() error {
	f, e := os.Open(q.Dir)
	if e != nil {
		return e
	}
	defer f.Close()
	return f.Sync()
}
func (q *Queue) status() (Status, error) {
	var s Status
	var fs syscall.Statfs_t
	if e := syscall.Statfs(q.Dir, &fs); e != nil {
		return s, e
	}
	s.FreeBytes = fs.Bavail * uint64(fs.Bsize)
	ns, e := q.names()
	if e != nil {
		return s, e
	}
	s.Records = len(ns)
	for _, n := range ns {
		r, e := q.read(n)
		if e != nil {
			return s, e
		}
		s.Coalesced += r.Coalesced
		for _, v := range []*Event{r.Start, r.Interim, r.Stop} {
			if v != nil && !v.Ack {
				s.Pending++
				age := q.Now().Unix() - v.At
				if age > s.OldestSeconds {
					s.OldestSeconds = age
				}
			}
		}
	}
	s.Restricted = s.Records >= q.Limit || s.Records >= Slots || s.FreeBytes < q.MinFree+2*BankSize || s.OldestSeconds >= int64(Retention.Seconds())
	return s, nil
}
func (q *Queue) Status() (Status, error) {
	f, e := q.lock()
	if e != nil {
		return Status{}, e
	}
	defer unlock(f)
	return q.status()
}

// Put is synchronous: success means a valid bank and its directory entry were fsynced.
// Each admitted session reserves both banks for its entire Start/interim/Stop lifecycle.
func (q *Queue) Put(packet []byte, address, source, secret string) error {
	typ, sid, e := Identity(packet)
	if e != nil {
		return e
	}
	key := sha256.Sum256([]byte(source + "\x00" + sid))
	name := hex.EncodeToString(key[:]) + ".slot"
	f, e := q.lock()
	if e != nil {
		return e
	}
	defer unlock(f)
	r, e := q.read(name)
	fresh := false
	if os.IsNotExist(e) {
		if typ != 1 {
			return errors.New("accounting event without durable Start")
		}
		st, e := q.status()
		if e != nil {
			return e
		}
		if st.Restricted {
			return errors.New("accounting backpressure: new admission denied")
		}
		// Create and allocate under a temporary name. An interrupted allocation is never a queued event.
		p := filepath.Join(q.Dir, name+".new")
		sf, e := os.OpenFile(p, os.O_CREATE|os.O_TRUNC|os.O_RDWR, 0600)
		if e != nil {
			return e
		}
		e = syscall.Fallocate(int(sf.Fd()), 0, 0, 2*BankSize)
		if e == nil {
			e = sf.Sync()
		}
		sf.Close()
		if e != nil {
			os.Remove(p)
			return e
		}
		r = &Record{Session: sid, Address: address, Source: source, Secret: secret}
		fresh = true
	} else if e != nil {
		return e
	}
	if typ == 1 && r.Start != nil {
		return nil
	} // Same session's local handoff is idempotent.
	if r.Stop != nil {
		if typ == 2 {
			return nil
		}
		return errors.New("interim after durable Stop")
	}
	r.Sequence++
	at := q.Now().Unix()
	attrs, _ := attributes(packet)
	for _, a := range attrs {
		if a[0] == 55 && len(a) == 6 {
			at = int64(binary.BigEndian.Uint32(a[2:]))
		}
	}
	v := &Event{ID: r.Sequence, At: at, Packet: append([]byte(nil), packet...)}
	switch typ {
	case 1:
		r.Start = v
	case 2:
		r.Stop = v
	case 3:
		if r.Interim != nil && !r.Interim.Ack {
			r.Coalesced++
		}
		r.Interim = v
	}
	target := name
	if fresh {
		target += ".new"
	}
	if e = q.write(target, r); e != nil {
		return e
	}
	if fresh {
		if e = os.Rename(filepath.Join(q.Dir, target), filepath.Join(q.Dir, name)); e != nil {
			return e
		}
	}
	return q.syncDir()
}
func next(r *Record) *Event {
	for _, e := range []*Event{r.Start, r.Interim, r.Stop} {
		if e != nil && !e.Ack {
			return e
		}
	}
	return nil
}

// Pending returns at most one event per session, always Start before interim before Stop.
func (q *Queue) Pending() (map[string]Record, error) {
	f, e := q.lock()
	if e != nil {
		return nil, e
	}
	defer unlock(f)
	ns, e := q.names()
	if e != nil {
		return nil, e
	}
	out := map[string]Record{}
	for _, n := range ns {
		r, e := q.read(n)
		if e != nil {
			return nil, e
		}
		if next(r) != nil {
			out[n] = *r
		}
	}
	return out, nil
}
func (q *Queue) Ack(name string, id uint64) error {
	if filepath.Base(name) != name {
		return errors.New("invalid slot")
	}
	f, e := q.lock()
	if e != nil {
		return e
	}
	defer unlock(f)
	r, e := q.read(name)
	if os.IsNotExist(e) {
		return nil
	}
	if e != nil {
		return e
	}
	for _, v := range []*Event{r.Start, r.Interim, r.Stop} {
		if v != nil && v.ID == id {
			v.Ack = true
		}
	}
	if r.Stop != nil && next(r) == nil {
		if e = os.Remove(filepath.Join(q.Dir, name)); e != nil {
			return e
		}
		return q.syncDir()
	}
	return q.write(name, r)
}
func (q *Queue) String() string {
	s, e := q.Status()
	if e != nil {
		return fmt.Sprint(e)
	}
	b, _ := json.Marshal(s)
	return string(b)
}
