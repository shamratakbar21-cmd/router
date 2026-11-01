package agent

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"
)

func TestRoutingLabProvidesForwardingFirewallAndNat(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("routing lab requires root")
	}

	suffix := shortSuffix()
	routerNamespace := "nrtr" + suffix
	lanNamespace := "nrla" + suffix
	wanNamespace := "nrwa" + suffix
	routerLanInterface := "rl" + suffix
	lanInterface := "li" + suffix
	routerWanInterface := "rw" + suffix
	wanInterface := "wi" + suffix

	createNamespace(t, routerNamespace)
	createNamespace(t, lanNamespace)
	createNamespace(t, wanNamespace)
	t.Cleanup(func() { deleteNamespace(routerNamespace) })
	t.Cleanup(func() { deleteNamespace(lanNamespace) })
	t.Cleanup(func() { deleteNamespace(wanNamespace) })

	runCommand(t, "ip", "link", "add", routerLanInterface, "type", "veth", "peer", "name", lanInterface)
	runCommand(t, "ip", "link", "add", routerWanInterface, "type", "veth", "peer", "name", wanInterface)
	t.Cleanup(func() { runCommandIgnoreError("ip", "link", "delete", routerLanInterface) })
	t.Cleanup(func() { runCommandIgnoreError("ip", "link", "delete", routerWanInterface) })

	runCommand(t, "ip", "link", "set", routerLanInterface, "netns", routerNamespace)
	runCommand(t, "ip", "link", "set", lanInterface, "netns", lanNamespace)
	runCommand(t, "ip", "link", "set", routerWanInterface, "netns", routerNamespace)
	runCommand(t, "ip", "link", "set", wanInterface, "netns", wanNamespace)

	bringLoopbackUp(t, routerNamespace)
	bringLoopbackUp(t, lanNamespace)
	bringLoopbackUp(t, wanNamespace)

	runInNamespace(t, routerNamespace, "ip", "addr", "add", "192.0.2.1/24", "dev", routerLanInterface)
	runInNamespace(t, routerNamespace, "ip", "addr", "add", "198.51.100.1/24", "dev", routerWanInterface)
	runInNamespace(t, routerNamespace, "ip", "link", "set", routerLanInterface, "up")
	runInNamespace(t, routerNamespace, "ip", "link", "set", routerWanInterface, "up")
	runInNamespace(t, routerNamespace, "sysctl", "-w", "net.ipv4.ip_forward=1")

	runInNamespace(t, lanNamespace, "ip", "addr", "add", "192.0.2.2/24", "dev", lanInterface)
	runInNamespace(t, lanNamespace, "ip", "link", "set", lanInterface, "up")
	runInNamespace(t, lanNamespace, "ip", "route", "add", "default", "via", "192.0.2.1")

	runInNamespace(t, wanNamespace, "ip", "addr", "add", "198.51.100.2/24", "dev", wanInterface)
	runInNamespace(t, wanNamespace, "ip", "link", "set", wanInterface, "up")
	runInNamespace(t, wanNamespace, "ip", "route", "add", "192.0.2.0/24", "via", "198.51.100.1")

	runInNamespace(t, routerNamespace, "nft", "add", "table", "inet", "filter")
	runInNamespace(t, routerNamespace, "nft", "add", "chain", "inet", "filter", "forward", "{ type filter hook forward priority filter; policy drop; }")
	runInNamespace(t, routerNamespace, "nft", "add", "rule", "inet", "filter", "forward", "ct", "state", "established,related", "accept")
	runInNamespace(t, routerNamespace, "nft", "add", "rule", "inet", "filter", "forward", "iifname", routerLanInterface, "oifname", routerWanInterface, "ct", "state", "new,established", "accept")

	runInNamespace(t, lanNamespace, "ping", "-c", "1", "-W", "1", "198.51.100.2")

	reversePing := exec.Command("ip", "netns", "exec", wanNamespace, "ping", "-c", "1", "-W", "1", "192.0.2.2")
	if err := reversePing.Run(); err == nil {
		t.Fatal("expected firewall policy to block unsolicited reverse forwarding")
	}

	runInNamespace(t, wanNamespace, "ip", "route", "del", "192.0.2.0/24", "via", "198.51.100.1")
	runInNamespace(t, routerNamespace, "nft", "add", "table", "ip", "nat")
	runInNamespace(t, routerNamespace, "nft", "add", "chain", "ip", "nat", "postrouting", "{ type nat hook postrouting priority srcnat; policy accept; }")
	runInNamespace(t, routerNamespace, "nft", "add", "rule", "ip", "nat", "postrouting", "oifname", routerWanInterface, "masquerade")

	tcpdumpContext, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	tcpdump := exec.CommandContext(
		tcpdumpContext,
		"ip", "netns", "exec", wanNamespace,
		"tcpdump", "-n", "-i", wanInterface, "-c", "1", "icmp[icmptype] = icmp-echo",
	)

	tcpdumpOutput, err := tcpdump.StdoutPipe()
	if err != nil {
		t.Fatalf("failed to capture tcpdump output: %v", err)
	}

	capturedOutputChannel := make(chan []byte, 1)
	capturedErrorChannel := make(chan error, 1)

	if err := tcpdump.Start(); err != nil {
		t.Fatalf("failed to start tcpdump: %v", err)
	}

	go func() {
		capturedBytes, readErr := io.ReadAll(tcpdumpOutput)
		if readErr != nil {
			capturedErrorChannel <- readErr
			return
		}

		capturedOutputChannel <- capturedBytes
	}()

	time.Sleep(500 * time.Millisecond)
	runInNamespace(t, lanNamespace, "ping", "-c", "1", "-W", "1", "198.51.100.2")

	if err := tcpdump.Wait(); err != nil {
		t.Fatalf("tcpdump did not observe NAT traffic: %v", err)
	}

	select {
	case err := <-capturedErrorChannel:
		t.Fatalf("failed to read tcpdump output: %v", err)
	default:
	}

	capturedBytes := <-capturedOutputChannel
	capturedOutput := string(capturedBytes)
	if !strings.Contains(capturedOutput, "198.51.100.1 > 198.51.100.2") {
		t.Fatalf("expected masqueraded source address in tcpdump output, got: %s", capturedOutput)
	}
}

func TestRoutingLabProvidesBridgeMembershipAndVlanInterfaceReachability(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("routing lab requires root")
	}

	suffix := shortSuffix()
	bridgeNamespace := "nrbr" + suffix
	hostANamespace := "nrah" + suffix
	hostBNamespace := "nrbh" + suffix
	switchPortA := "sa" + suffix
	hostAInterface := "ha" + suffix
	switchPortB := "sb" + suffix
	hostBInterface := "hb" + suffix

	createNamespace(t, bridgeNamespace)
	createNamespace(t, hostANamespace)
	createNamespace(t, hostBNamespace)
	t.Cleanup(func() { deleteNamespace(bridgeNamespace) })
	t.Cleanup(func() { deleteNamespace(hostANamespace) })
	t.Cleanup(func() { deleteNamespace(hostBNamespace) })

	runCommand(t, "ip", "link", "add", switchPortA, "type", "veth", "peer", "name", hostAInterface)
	runCommand(t, "ip", "link", "add", switchPortB, "type", "veth", "peer", "name", hostBInterface)
	t.Cleanup(func() { runCommandIgnoreError("ip", "link", "delete", switchPortA) })
	t.Cleanup(func() { runCommandIgnoreError("ip", "link", "delete", switchPortB) })

	runCommand(t, "ip", "link", "set", switchPortA, "netns", bridgeNamespace)
	runCommand(t, "ip", "link", "set", hostAInterface, "netns", hostANamespace)
	runCommand(t, "ip", "link", "set", switchPortB, "netns", bridgeNamespace)
	runCommand(t, "ip", "link", "set", hostBInterface, "netns", hostBNamespace)

	bringLoopbackUp(t, bridgeNamespace)
	bringLoopbackUp(t, hostANamespace)
	bringLoopbackUp(t, hostBNamespace)

	runInNamespace(t, bridgeNamespace, "ip", "link", "add", "br0", "type", "bridge", "vlan_filtering", "1")
	runInNamespace(t, bridgeNamespace, "ip", "link", "set", "br0", "up")
	runInNamespace(t, bridgeNamespace, "ip", "link", "set", switchPortA, "master", "br0")
	runInNamespace(t, bridgeNamespace, "ip", "link", "set", switchPortB, "master", "br0")
	runInNamespace(t, bridgeNamespace, "ip", "link", "set", switchPortA, "up")
	runInNamespace(t, bridgeNamespace, "ip", "link", "set", switchPortB, "up")

	runInNamespace(t, bridgeNamespace, "bridge", "vlan", "add", "dev", switchPortA, "vid", "100", "pvid", "untagged")
	runInNamespace(t, bridgeNamespace, "bridge", "vlan", "add", "dev", switchPortB, "vid", "100", "pvid", "untagged")
	runInNamespace(t, bridgeNamespace, "bridge", "vlan", "add", "dev", "br0", "self", "vid", "100")
	runInNamespace(t, bridgeNamespace, "ip", "link", "add", "link", "br0", "name", "br0.100", "type", "vlan", "id", "100")
	runInNamespace(t, bridgeNamespace, "ip", "addr", "add", "10.0.100.1/24", "dev", "br0.100")
	runInNamespace(t, bridgeNamespace, "ip", "link", "set", "br0.100", "up")

	runInNamespace(t, hostANamespace, "ip", "addr", "add", "10.0.100.2/24", "dev", hostAInterface)
	runInNamespace(t, hostANamespace, "ip", "link", "set", hostAInterface, "up")
	runInNamespace(t, hostBNamespace, "ip", "addr", "add", "10.0.100.3/24", "dev", hostBInterface)
	runInNamespace(t, hostBNamespace, "ip", "link", "set", hostBInterface, "up")

	runInNamespace(t, hostANamespace, "ping", "-c", "1", "-W", "1", "10.0.100.1")
	runInNamespace(t, hostBNamespace, "ping", "-c", "1", "-W", "1", "10.0.100.2")
}

func TestRoutingApplyReconcilesDesiredConfiguration(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("routing apply requires root")
	}

	suffix := shortSuffix()
	routerNamespace := "nrap" + suffix
	createNamespace(t, routerNamespace)
	t.Cleanup(func() { deleteNamespace(routerNamespace) })

	server := NewServer(ServerOptions{
		SocketPath:       "/tmp/nur-agent-test.sock",
		SocketMode:       0o660,
		SocketGroupID:    -1,
		AllowedPeerUIDs:  map[uint32]struct{}{},
		AllowedPeerGIDs:  map[uint32]struct{}{},
		OperationTimeout: 15 * time.Second,
	})

	firstDesiredConfiguration := routingDesiredConfiguration{
		Namespace: routerNamespace,
		Management: routingManagement{
			Interface: "mgmt0",
			CIDR:      "192.0.2.1/24",
		},
		Forwarding: routingForwarding{IPv4: true},
		Interfaces: []routingInterface{
			{Name: "br0", Type: "bridge", State: "up"},
			{Name: "vlan10", Type: "vlan", Parent: "br0", VLANID: 10, State: "up"},
		},
		Addresses: []routingAddress{
			{Interface: "br0", CIDR: "198.51.100.1/24"},
		},
		Routes: []routingRoute{
			{Destination: "203.0.113.0/24", Device: "br0", Metric: 10},
		},
		BridgeVLANs: []routingBridgeVLAN{
			{Device: "br0", VID: 10, Self: true},
		},
		Firewall: routingFirewall{
			Rules: []routingFirewallRule{
				{Chain: "forward", Action: "accept", InInterface: "br0", OutInterface: "mgmt0"},
			},
			NAT: []routingNATRule{
				{Chain: "postrouting", Action: "masquerade", OutInterface: "mgmt0"},
			},
		},
	}

	appliedState, err := server.applyRouting(map[string]interface{}{
		"name":                  routerNamespace,
		"desired_configuration": firstDesiredConfiguration,
	})
	if err != nil {
		t.Fatalf("expected routing apply to succeed: %v", err)
	}

	firstObservedInterfaces := decodeInterfaceNames(t, appliedState["links"])
	if !containsString(firstObservedInterfaces, "mgmt0") || !containsString(firstObservedInterfaces, "br0") || !containsString(firstObservedInterfaces, "vlan10") {
		t.Fatalf("expected reconciled interfaces to exist, got: %v", firstObservedInterfaces)
	}

	if got := appliedState["forwarding"].(map[string]interface{})["ipv4"]; got != "1" {
		t.Fatalf("expected ipv4 forwarding to be enabled, got: %v", got)
	}

	secondDesiredConfiguration := routingDesiredConfiguration{
		Namespace: routerNamespace,
		Management: routingManagement{
			Interface: "mgmt0",
			CIDR:      "192.0.2.2/24",
		},
		Forwarding: routingForwarding{IPv4: false},
	}

	reconciledState, err := server.applyRouting(map[string]interface{}{
		"name":                  routerNamespace,
		"desired_configuration": secondDesiredConfiguration,
	})
	if err != nil {
		t.Fatalf("expected second routing apply to succeed: %v", err)
	}

	secondObservedInterfaces := decodeInterfaceNames(t, reconciledState["links"])
	if containsString(secondObservedInterfaces, "br0") || containsString(secondObservedInterfaces, "vlan10") {
		t.Fatalf("expected reconciler to remove stale managed interfaces, got: %v", secondObservedInterfaces)
	}

	if got := reconciledState["forwarding"].(map[string]interface{})["ipv4"]; got != "0" {
		t.Fatalf("expected ipv4 forwarding to be disabled, got: %v", got)
	}
}

func createNamespace(t *testing.T, namespace string) {
	t.Helper()
	runCommand(t, "ip", "netns", "add", namespace)
}

func deleteNamespace(namespace string) {
	runCommandIgnoreError("ip", "netns", "delete", namespace)
}

func bringLoopbackUp(t *testing.T, namespace string) {
	t.Helper()
	runInNamespace(t, namespace, "ip", "link", "set", "lo", "up")
}

func runInNamespace(t *testing.T, namespace string, command string, arguments ...string) string {
	t.Helper()
	args := append([]string{"netns", "exec", namespace, command}, arguments...)
	return runCommand(t, "ip", args...)
}

func runCommand(t *testing.T, command string, arguments ...string) string {
	t.Helper()

	context, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()

	output, err := exec.CommandContext(context, command, arguments...).CombinedOutput()
	if err != nil {
		t.Fatalf("%s %v failed: %v (%s)", command, arguments, err, string(output))
	}

	return string(output)
}

func runCommandIgnoreError(command string, arguments ...string) {
	context, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	_, _ = exec.CommandContext(context, command, arguments...).CombinedOutput()
}

func shortSuffix() string {
	return fmt.Sprintf("%04d", time.Now().UnixNano()%10000)
}

func decodeInterfaceNames(t *testing.T, rawValue interface{}) []string {
	t.Helper()

	rawMessage, ok := rawValue.(json.RawMessage)
	if !ok {
		t.Fatalf("expected raw json message, got %T", rawValue)
	}

	var links []map[string]interface{}
	if err := json.Unmarshal(rawMessage, &links); err != nil {
		t.Fatalf("failed to decode links: %v", err)
	}

	names := make([]string, 0, len(links))
	for _, link := range links {
		if interfaceName, ok := link["ifname"].(string); ok {
			names = append(names, interfaceName)
		}
	}

	return names
}

func containsString(values []string, expected string) bool {
	for _, value := range values {
		if value == expected {
			return true
		}
	}

	return false
}
