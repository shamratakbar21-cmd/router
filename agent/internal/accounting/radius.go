package accounting

import (
	"crypto/md5" // RADIUS Accounting uses MD5 authenticators (RFC 2866).
	"crypto/rand"
	"crypto/subtle"
	"encoding/binary"
	"errors"
	"net"
	"time"
)

func attributes(p []byte) ([][]byte, error) {
	if len(p) < 20 || len(p) > 4096 || int(binary.BigEndian.Uint16(p[2:4])) != len(p) {
		return nil, errors.New("invalid RADIUS length")
	}
	var out [][]byte
	for i := 20; i < len(p); {
		if i+2 > len(p) || p[i+1] < 2 || i+int(p[i+1]) > len(p) {
			return nil, errors.New("invalid RADIUS attribute")
		}
		n := int(p[i+1])
		out = append(out, p[i:i+n])
		i += n
	}
	return out, nil
}
func Identity(p []byte) (uint32, string, error) {
	a, e := attributes(p)
	if e != nil {
		return 0, "", e
	}
	if p[0] != 4 {
		return 0, "", errors.New("not Accounting-Request")
	}
	var kind uint32
	var sid string
	var kinds, ids int
	for _, v := range a {
		switch v[0] {
		case 40:
			if len(v) != 6 {
				return 0, "", errors.New("invalid status")
			}
			kind = binary.BigEndian.Uint32(v[2:])
			kinds++
		case 44:
			sid = string(v[2:])
			ids++
		case 80:
			return 0, "", errors.New("unexpected Message-Authenticator in engine accounting event")
		}
	}
	if kinds != 1 || ids != 1 || sid == "" || (kind != 1 && kind != 2 && kind != 3) {
		return 0, "", errors.New("invalid accounting identity")
	}
	return kind, sid, nil
}
func intAttr(t byte, n uint32) []byte {
	b := []byte{t, 6, 0, 0, 0, 0}
	binary.BigEndian.PutUint32(b[2:], n)
	return b
}
func Request(e *Event, secret string, now time.Time) ([]byte, error) {
	a, err := attributes(e.Packet)
	if err != nil {
		return nil, err
	}
	p := make([]byte, 20)
	p[0] = 4
	if _, err = rand.Read(p[1:2]); err != nil {
		return nil, err
	}
	for _, v := range a {
		if v[0] != 41 && v[0] != 55 {
			p = append(p, v...)
		}
	}
	delay := now.Unix() - e.At
	if delay < 0 {
		delay = 0
	}
	if delay > int64(^uint32(0)) {
		delay = int64(^uint32(0))
	}
	p = append(p, intAttr(41, uint32(delay))...)
	p = append(p, intAttr(55, uint32(e.At))...)
	if len(p) > 4096 {
		return nil, errors.New("accounting packet too large")
	}
	binary.BigEndian.PutUint16(p[2:4], uint16(len(p)))
	h := md5.New()
	h.Write(p)
	h.Write([]byte(secret))
	copy(p[4:20], h.Sum(nil))
	return p, nil
}
func ValidResponse(p, req []byte, secret string) bool {
	if len(p) < 20 || p[0] != 5 || p[1] != req[1] {
		return false
	}
	if _, e := attributes(p); e != nil {
		return false
	}
	h := md5.New()
	h.Write(p[:4])
	h.Write(req[4:20])
	h.Write(p[20:])
	h.Write([]byte(secret))
	return subtle.ConstantTimeCompare(h.Sum(nil), p[4:20]) == 1
}
func Deliver(r Record, now time.Time) (uint64, error) {
	ev := next(&r)
	if ev == nil {
		return 0, errors.New("no pending event")
	}
	p, e := Request(ev, r.Secret, now)
	if e != nil {
		return 0, e
	}
	remote, e := net.ResolveUDPAddr("udp4", r.Address)
	if e != nil {
		return 0, e
	}
	local := &net.UDPAddr{IP: net.ParseIP(r.Source)}
	if local.IP == nil {
		return 0, errors.New("invalid accounting source")
	}
	c, e := net.DialUDP("udp4", local, remote)
	if e != nil {
		return 0, e
	}
	defer c.Close()
	c.SetDeadline(time.Now().Add(time.Second))
	if _, e = c.Write(p); e != nil {
		return 0, e
	}
	buf := make([]byte, 4097)
	for {
		n, e := c.Read(buf)
		if e != nil {
			return 0, e
		}
		if ValidResponse(buf[:n], p, r.Secret) {
			return ev.ID, nil
		}
	}
}

// NASAddress binds all events in a session to the engine's original NAS identity.
func NASAddress(p []byte) (string, error) {
	attrs, e := attributes(p)
	if e != nil {
		return "", e
	}
	source := ""
	for _, a := range attrs {
		if a[0] == 4 {
			if len(a) != 6 || source != "" {
				return "", errors.New("invalid NAS identity")
			}
			source = net.IP(a[2:]).String()
		}
	}
	if source == "" {
		return "", errors.New("missing NAS identity")
	}
	return source, nil
}
