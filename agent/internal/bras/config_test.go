package bras

import (
	"strings"
	"testing"
)

func fixture() Config {
	return Config{Enabled: true, Interfaces: []string{"eth1.100"}, Auth: "local", Gateway: "10.55.0.1", MaxSessions: 20, Pools: []Pool{{"access", "10.55.0.2", "10.55.0.9"}}, Profiles: []Profile{{"basic", "access", 1024, 512}}, Subscribers: []Subscriber{{"alice", "test-pass", "basic"}}}
}
func TestRenderAdmissionAndProfile(t *testing.T) {
	c := fixture()
	conf, secrets, e := c.Render("test-key", 3)
	if e != nil {
		t.Fatal(e)
	}
	for _, v := range []string{"max-sessions=3", "ifname=nurbs%d", "single-session=deny", "interface=eth1.100", "reload-orphan=keep"} {
		if !strings.Contains(conf, v) {
			t.Fatal(v)
		}
	}
	if !strings.Contains(secrets, "alice * test-pass access 1024/512") {
		t.Fatal(secrets)
	}
}
func TestRejectUnsafeConfigAndPoolCollisions(t *testing.T) {
	cases := []func(*Config){func(c *Config) { c.Interfaces = []string{"eth1\ninterface=eth0"} }, func(c *Config) { c.Subscribers[0].Password = "pass\n* *" }, func(c *Config) { c.Pools = append(c.Pools, Pool{"other", "10.55.0.4", "10.55.0.8"}) }, func(c *Config) { c.Pools[0].Start = c.Gateway }, func(c *Config) { c.Profiles[0].Pool = "missing" }, func(c *Config) { c.Subscribers = append(c.Subscribers, c.Subscribers[0]) }}
	for i, mutate := range cases {
		c := fixture()
		mutate(&c)
		if c.Validate() == nil {
			t.Fatalf("case %d accepted", i)
		}
	}
}
func TestRadiusSeparatesAuthenticationAndRequiresBoundDAE(t *testing.T) {
	c := fixture()
	c.Auth = "radius"
	c.Radius = Radius{"192.0.2.10", "radius-secret", "192.0.2.2", "192.0.2.2", 10}
	conf, _, e := c.Render("key", 0)
	if e != nil {
		t.Fatal(e)
	}
	if !strings.Contains(conf, "acct-interim-interval=10") || !strings.Contains(conf, "blast-protection=1") || strings.Contains(conf, "\nauth_pap\nchap-secrets\n") {
		t.Fatal(conf)
	}
	c.Radius.DAE = "0.0.0.0"
	if c.Validate() == nil {
		t.Fatal("unbound DAE allowed")
	}
}
func TestDecodeRejectsTrailingAndUnknownData(t *testing.T) {
	for _, v := range []string{`{"enabled":false} {}`, `{"enabled":false} garbage`, `{"enabled":false,"unknown":1}`} {
		if _, e := Decode([]byte(v)); e == nil {
			t.Fatal(v)
		}
	}
}

func TestPoolRangesUseEngineSuffixSyntaxAcrossSubnets(t *testing.T) {
	c := fixture()
	c.Pools[0].Start = "10.55.0.254"
	c.Pools[0].End = "10.55.1.2"
	conf, _, err := c.Render("key", 0)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(conf, "10.55.0.254-255,name=access\n10.55.1.0-2,name=access") {
		t.Fatal(conf)
	}
	if strings.Contains(conf, "10.55.0.254-10.") {
		t.Fatal("full IPv4 end silently expands the engine range")
	}
}

// Accounting loss must not evict a subscriber whose authentication already passed.
// New authentication still uses RADIUS only; no local fallback module is loaded.
func TestRadiusOutagePolicyPreservesEstablishedSessionsWithoutFallback(t *testing.T) {
	c := fixture()
	c.Auth = "radius"
	c.Radius = Radius{"192.0.2.10", "radius-secret", "192.0.2.2", "192.0.2.2", 30}
	conf, _, err := c.Render("key", 0)
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"acct-timeout=0\n", "max-try=3\n", "timeout=3\n"} {
		if !strings.Contains(conf, want) {
			t.Fatalf("missing explicit outage policy %q", want)
		}
	}
	modules := strings.Split(conf, "[core]")[0]
	if strings.Contains(modules, "chap-secrets") {
		t.Fatal("implicit local authentication fallback")
	}
}
