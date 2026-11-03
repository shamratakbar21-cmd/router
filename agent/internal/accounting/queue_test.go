package accounting

import (
	"crypto/md5"
	"encoding/binary"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func packet(typ uint32, sid string) []byte {
	p := make([]byte, 20)
	p[0] = 4
	p = append(p, intAttr(40, typ)...)
	p = append(p, 44, byte(len(sid)+2))
	p = append(p, []byte(sid)...)
	binary.BigEndian.PutUint16(p[2:], uint16(len(p)))
	return p
}
func queue(t *testing.T) *Queue { t.Helper(); q := New(t.TempDir()); q.MinFree = 0; return q }
func put(t *testing.T, q *Queue, typ uint32, sid string) {
	t.Helper()
	if e := q.Put(packet(typ, sid), "127.0.0.1:1813", "127.0.0.1", "test-secret"); e != nil {
		t.Fatal(e)
	}
}
func TestRestartOrderCoalescingAndReservedStop(t *testing.T) {
	q := queue(t)
	q.Limit = 1
	put(t, q, 1, "session")
	put(t, q, 3, "session")
	put(t, q, 3, "session")
	if e := q.Put(packet(1, "other"), "x", "127.0.0.1", "s"); e == nil {
		t.Fatal("new admission accepted at capacity")
	}
	q.MinFree = ^uint64(0) - 2*BankSize
	put(t, q, 2, "session") // Existing reservation survives low-space admission threshold.
	q = New(q.Dir)
	p, e := q.Pending()
	if e != nil {
		t.Fatal(e)
	}
	if len(p) != 1 {
		t.Fatal(p)
	}
	for n, r := range p {
		if r.Coalesced != 1 || next(&r).ID != 1 || r.Stop.ID != 4 {
			t.Fatal(r)
		}
		for _, id := range []uint64{1, 3, 4} {
			if e = q.Ack(n, id); e != nil {
				t.Fatal(e)
			}
		}
	}
	p, e = q.Pending()
	if e != nil || len(p) != 0 {
		t.Fatal(p, e)
	}
}
func TestRetentionNeverDeletes(t *testing.T) {
	q := queue(t)
	now := time.Now()
	q.Now = func() time.Time { return now }
	put(t, q, 1, "old")
	q.Now = func() time.Time { return now.Add(Retention + time.Second) }
	s, e := q.Status()
	if e != nil || !s.Restricted || s.Pending != 1 {
		t.Fatal(s, e)
	}
	put(t, q, 2, "old")
	if e = q.Put(packet(1, "new"), "x", "127.0.0.1", "s"); e == nil {
		t.Fatal("retention alarm must close admission")
	}
}
func TestTornBankRecoveryAndPermissions(t *testing.T) {
	q := queue(t)
	put(t, q, 1, "a")
	put(t, q, 3, "a")
	ns, _ := q.names()
	p := filepath.Join(q.Dir, ns[0])
	f, e := os.OpenFile(p, os.O_RDWR, 0)
	if e != nil {
		t.Fatal(e)
	}
	f.WriteAt([]byte("torn"), 0)
	f.Sync()
	f.Close()
	r, e := q.read(ns[0])
	if e != nil || r.Start == nil || r.Interim != nil {
		t.Fatal(r, e)
	}
	st, _ := os.Stat(p)
	if st.Mode().Perm() != 0600 || st.Size() != 2*BankSize {
		t.Fatal(st)
	}
	st, _ = os.Stat(q.Dir)
	if st.Mode().Perm() != 0700 {
		t.Fatal(st)
	}
}
func response(req []byte, secret string) []byte {
	b := []byte{5, req[1], 0, 20}
	h := md5.New()
	h.Write(b)
	h.Write(req[4:20])
	h.Write([]byte(secret))
	return append(b, h.Sum(nil)...)
}
func TestAuthenticatedResponseDelayAndDuplicate(t *testing.T) {
	e := &Event{ID: 7, At: time.Now().Add(-45 * time.Second).Unix(), Packet: packet(2, "a")}
	p, err := Request(e, "secret", time.Now())
	if err != nil {
		t.Fatal(err)
	}
	attrs, _ := attributes(p)
	var delay uint32
	var at uint32
	for _, a := range attrs {
		if a[0] == 41 {
			delay = binary.BigEndian.Uint32(a[2:])
		}
		if a[0] == 55 {
			at = binary.BigEndian.Uint32(a[2:])
		}
	}
	if delay < 45 || at != uint32(e.At) {
		t.Fatal(delay, at)
	}
	good := response(p, "secret")
	if !ValidResponse(good, p, "secret") {
		t.Fatal("valid rejected")
	}
	bad := append([]byte(nil), good...)
	bad[1]++
	if ValidResponse(bad, p, "secret") {
		t.Fatal("ID mismatch accepted")
	}
	if ValidResponse(response(p, "wrong"), p, "secret") {
		t.Fatal("forged response accepted")
	}
	bad = append(good, 0)
	if ValidResponse(bad, p, "secret") {
		t.Fatal("length mismatch accepted")
	}
}
func TestRealUDPRejectsForgedThenAcknowledges(t *testing.T) {
	c, e := net.ListenUDP("udp4", &net.UDPAddr{IP: net.ParseIP("127.0.0.1")})
	if e != nil {
		t.Fatal(e)
	}
	defer c.Close()
	done := make(chan struct{})
	go func() {
		defer close(done)
		b := make([]byte, 4096)
		n, a, e := c.ReadFromUDP(b)
		if e != nil {
			return
		}
		c.WriteToUDP(response(b[:n], "wrong"), a)
		c.WriteToUDP(response(b[:n], "secret"), a)
	}()
	r := Record{Address: c.LocalAddr().String(), Source: "127.0.0.1", Secret: "secret", Start: &Event{ID: 1, At: time.Now().Unix(), Packet: packet(1, "a")}}
	if id, e := Deliver(r, time.Now()); e != nil || id != 1 {
		t.Fatal(id, e)
	}
	<-done
}

func TestExistingSessionSurvivesDisabledOrChangedConfiguration(t *testing.T) {
	q := queue(t)
	put(t, q, 1, "existing")
	if e := q.Put(packet(2, "existing"), "", "127.0.0.1", ""); e != nil {
		t.Fatal(e)
	}
	rows, e := q.Pending()
	if e != nil {
		t.Fatal(e)
	}
	for _, r := range rows {
		if r.Secret != "test-secret" || r.Address != "127.0.0.1:1813" || r.Stop == nil {
			t.Fatal("original destination or Stop lost")
		}
	}
	p := packet(1, "nas")
	p = append(p, 4, 6, 192, 0, 2, 1)
	binary.BigEndian.PutUint16(p[2:], uint16(len(p)))
	source, e := NASAddress(p)
	if e != nil || source != "192.0.2.1" {
		t.Fatal(source, e)
	}
}
