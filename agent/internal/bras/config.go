// Package bras renders the separately versioned experimental access concentrator.
package bras

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/netip"
	"regexp"
	"strings"
)

type Pool struct {
	Name  string `json:"name"`
	Start string `json:"start"`
	End   string `json:"end"`
}
type Profile struct {
	Name string `json:"name"`
	Pool string `json:"pool"`
	Down int    `json:"down_kbit"`
	Up   int    `json:"up_kbit"`
}
type Subscriber struct {
	Username string `json:"username"`
	Password string `json:"password"`
	Profile  string `json:"profile"`
}
type Radius struct {
	Address string `json:"address"`
	Secret  string `json:"secret"`
	NAS     string `json:"nas_address"`
	DAE     string `json:"dae_address"`
	Interim int    `json:"interim_seconds"`
}
type Config struct {
	Enabled     bool         `json:"enabled"`
	Interfaces  []string     `json:"access_interfaces"`
	Auth        string       `json:"auth_mode"`
	Gateway     string       `json:"gateway"`
	MaxSessions int          `json:"max_sessions"`
	Pools       []Pool       `json:"pools"`
	Profiles    []Profile    `json:"profiles"`
	Subscribers []Subscriber `json:"subscribers"`
	Radius      Radius       `json:"radius"`
}

var name = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$`)
var device = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_.-]{0,14}$`)
var credential = regexp.MustCompile(`^[A-Za-z0-9_@.!:+/-]{1,128}$`)

func ipv4(s string) bool {
	a, e := netip.ParseAddr(s)
	return e == nil && a.Is4() && !a.IsUnspecified() && !a.IsMulticast()
}
func Decode(data []byte) (Config, error) {
	var c Config
	d := json.NewDecoder(strings.NewReader(string(data)))
	d.DisallowUnknownFields()
	if e := d.Decode(&c); e != nil {
		return c, e
	}
	if d.Decode(new(any)) != io.EOF {
		return c, errors.New("trailing JSON")
	}
	return c, c.Validate()
}
func (c Config) Validate() error {
	if !c.Enabled {
		return nil
	}
	if len(c.Pools) > 128 || len(c.Profiles) > 128 || len(c.Subscribers) > 10000 {
		return errors.New("configuration collection limit exceeded")
	}
	if len(c.Interfaces) == 0 || len(c.Interfaces) > 32 || !ipv4(c.Gateway) || c.MaxSessions < 1 || c.MaxSessions > 100000 {
		return errors.New("access interfaces, IPv4 gateway and bounded session limit required")
	}
	seen := map[string]bool{}
	for _, i := range c.Interfaces {
		if !device.MatchString(i) || seen[i] {
			return errors.New("invalid or duplicate access interface")
		}
		seen[i] = true
	}
	pools := map[string]bool{}
	addresses := map[netip.Addr]bool{}
	for _, p := range c.Pools {
		if !name.MatchString(p.Name) || pools[p.Name] || !ipv4(p.Start) || !ipv4(p.End) {
			return errors.New("invalid or duplicate pool")
		}
		pools[p.Name] = true
		a, _ := netip.ParseAddr(p.Start)
		b, _ := netip.ParseAddr(p.End)
		if a.Compare(b) > 0 {
			return errors.New("reversed pool")
		}
		count := 0
		for ; a.Compare(b) <= 0; a = a.Next() {
			count++
			if count > 65536 || len(addresses) >= 131072 || addresses[a] || a.String() == c.Gateway {
				return errors.New("overlapping, oversized or gateway-containing pool")
			}
			addresses[a] = true
		}
	}
	if len(pools) == 0 {
		return errors.New("pool required")
	}
	profiles := map[string]bool{}
	for _, p := range c.Profiles {
		if !name.MatchString(p.Name) || profiles[p.Name] || !pools[p.Pool] || p.Down < 8 || p.Up < 8 || p.Down > 10000000 || p.Up > 10000000 {
			return errors.New("invalid profile")
		}
		profiles[p.Name] = true
	}
	if c.Auth == "local" {
		users := map[string]bool{}
		for _, s := range c.Subscribers {
			if !credential.MatchString(s.Username) || !credential.MatchString(s.Password) || users[s.Username] || !profiles[s.Profile] {
				return errors.New("invalid subscriber or profile")
			}
			users[s.Username] = true
		}
		if len(users) == 0 {
			return errors.New("local subscriber required")
		}
	} else if c.Auth == "radius" {
		r := c.Radius
		if !ipv4(r.Address) || !ipv4(r.NAS) || !ipv4(r.DAE) || !credential.MatchString(r.Secret) || r.Interim < 10 || r.Interim > 3600 {
			return errors.New("invalid RADIUS configuration")
		}
	} else {
		return errors.New("auth_mode must be local or radius")
	}
	return nil
}

// Render uses only validated tokens. One simultaneous session per username is denied,
// never replaced. Pools remain leased across reload; existing sessions are preserved.
func (c Config) Render(cliPassword string, licenseLimit int) (string, string, error) {
	if e := c.Validate(); e != nil {
		return "", "", e
	}
	if !c.Enabled {
		return "", "", nil
	}
	if !credential.MatchString(cliPassword) {
		return "", "", errors.New("invalid CLI credential")
	}
	limit := c.MaxSessions
	if licenseLimit > 0 && licenseLimit < limit {
		limit = licenseLimit
	}
	modules := "chap-secrets"
	if c.Auth == "radius" {
		modules = "radius"
	}
	var conf, secrets strings.Builder
	fmt.Fprintf(&conf, "[modules]\nlog_file\npppoe\nauth_pap\n%s\nippool\nshaper\n[core]\nlog-error=/var/log/accel-ppp/core.log\nthread-count=2\n[common]\nsingle-session=deny\nmax-sessions=%d\n[ppp]\nmtu=1492\nmru=1492\nipv4=require\nipv6=deny\nlcp-echo-interval=10\nlcp-echo-failure=3\n[pppoe]\nac-name=NUR-BRAS-EXPERIMENTAL\nservice-name=nur-access\naccept-blank-service=1\nifname=nurbs%%d\n", modules, limit)
	for _, i := range c.Interfaces {
		fmt.Fprintf(&conf, "interface=%s\n", i)
	}
	fmt.Fprintf(&conf, "[ip-pool]\ngw-ip-address=%s\nattr=Framed-Pool\nreload-orphan=keep\n", c.Gateway)
	for _, p := range c.Pools {
		start, _ := netip.ParseAddr(p.Start)
		end, _ := netip.ParseAddr(p.End)
		for start.Compare(end) <= 0 {
			octets := start.As4()
			octets[3] = 255
			last := netip.AddrFrom4(octets)
			if last.Compare(end) > 0 {
				last = end
			}
			fmt.Fprintf(&conf, "%s-%d,name=%s\n", start.String(), last.As4()[3], p.Name)
			start = last.Next()
		}
	}
	fmt.Fprintf(&conf, "[chap-secrets]\ngw-ip-address=%s\nchap-secrets=/var/run/nur-bras/chap-secrets\n[shaper]\nattr=Filter-Id\nup-limiter=police\ndown-limiter=tbf\n[cli]\ntcp=127.0.0.1:2001\npassword=%s\n[log]\nlog-file=/var/log/accel-ppp/access.log\nlevel=3\n", c.Gateway, cliPassword)
	if c.Auth == "radius" {
		r := c.Radius
		fmt.Fprintf(&conf, "[radius]\ndictionary=/usr/share/accel-ppp/radius/dictionary\nnas-identifier=nur-bras\nnas-ip-address=%s\ngw-ip-address=%s\nserver=%s,%s,auth-port=1812,acct-port=1813\ndae-server=%s:3799,%s\nacct-interim-interval=%d\nacct-timeout=0\nmax-try=3\ntimeout=3\nblast-protection=1\nrequire-nas-identification=1\n", r.NAS, c.Gateway, r.Address, r.Secret, r.DAE, r.Secret, r.Interim)
	}
	for _, s := range c.Subscribers {
		for _, p := range c.Profiles {
			if p.Name == s.Profile {
				fmt.Fprintf(&secrets, "%s * %s %s %d/%d\n", s.Username, s.Password, p.Pool, p.Down, p.Up)
			}
		}
	}
	return conf.String(), secrets.String(), nil
}
