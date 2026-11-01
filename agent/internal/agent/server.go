package agent

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

var namePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$`)
var interfacePattern = regexp.MustCompile(`^[a-zA-Z0-9._-]{1,15}$`)

type ServerOptions struct {
	SocketPath       string
	SocketMode       os.FileMode
	SocketGroupID    int
	AllowedPeerUIDs  map[uint32]struct{}
	AllowedPeerGIDs  map[uint32]struct{}
	OperationTimeout time.Duration
}

type Server struct {
	socketPath       string
	socketMode       os.FileMode
	socketGroupID    int
	allowedPeerUIDs  map[uint32]struct{}
	allowedPeerGIDs  map[uint32]struct{}
	operationTimeout time.Duration
	mu               sync.Mutex
}

type request struct {
	Operation  string                 `json:"operation"`
	Parameters map[string]interface{} `json:"parameters"`
}

type response struct {
	Success bool                   `json:"success"`
	Data    map[string]interface{} `json:"data,omitempty"`
	Error   *responseError         `json:"error,omitempty"`
}

type responseError struct {
	Message string `json:"message"`
}

type routingDesiredConfiguration struct {
	Namespace   string              `json:"namespace"`
	Management  routingManagement   `json:"management"`
	Forwarding  routingForwarding   `json:"forwarding"`
	Interfaces  []routingInterface  `json:"interfaces"`
	Addresses   []routingAddress    `json:"addresses"`
	Routes      []routingRoute      `json:"routes"`
	BridgeVLANs []routingBridgeVLAN `json:"bridge_vlans"`
	Firewall    routingFirewall     `json:"firewall"`
}

type routingManagement struct {
	Interface string `json:"interface"`
	CIDR      string `json:"cidr"`
}

type routingForwarding struct {
	IPv4 bool `json:"ipv4"`
}

type routingInterface struct {
	Name   string `json:"name"`
	Type   string `json:"type"`
	State  string `json:"state"`
	Master string `json:"master"`
	Parent string `json:"parent"`
	VLANID int    `json:"vlan_id"`
	MTU    int    `json:"mtu"`
}

type routingAddress struct {
	Interface string `json:"interface"`
	CIDR      string `json:"cidr"`
}

type routingRoute struct {
	Destination string `json:"destination"`
	Gateway     string `json:"gateway"`
	Device      string `json:"device"`
	Metric      int    `json:"metric"`
	Protocol    string `json:"protocol"`
}

type routingBridgeVLAN struct {
	Device   string `json:"device"`
	VID      int    `json:"vid"`
	PVID     bool   `json:"pvid"`
	Untagged bool   `json:"untagged"`
	Self     bool   `json:"self"`
}

type routingFirewall struct {
	Rules []routingFirewallRule `json:"rules"`
	NAT   []routingNATRule      `json:"nat"`
}

type routingFirewallRule struct {
	Chain        string `json:"chain"`
	Action       string `json:"action"`
	Protocol     string `json:"protocol"`
	Source       string `json:"source"`
	Destination  string `json:"destination"`
	InInterface  string `json:"in_interface"`
	OutInterface string `json:"out_interface"`
}

type routingNATRule struct {
	Chain        string `json:"chain"`
	Action       string `json:"action"`
	Protocol     string `json:"protocol"`
	ToAddress    string `json:"to_address"`
	Source       string `json:"source"`
	Destination  string `json:"destination"`
	InInterface  string `json:"in_interface"`
	OutInterface string `json:"out_interface"`
}

func NewServerOptions(
	socketPath string,
	socketMode string,
	socketGroup string,
	allowedPeerUIDs string,
	allowedPeerGIDs string,
	operationTimeout time.Duration,
) (ServerOptions, error) {
	parsedSocketMode, err := strconv.ParseUint(socketMode, 8, 32)
	if err != nil {
		return ServerOptions{}, fmt.Errorf("invalid socket mode %q: %w", socketMode, err)
	}

	socketGroupID, err := resolveGroupID(socketGroup)
	if err != nil {
		return ServerOptions{}, err
	}

	peerUIDs, err := resolvePeerPrincipals(allowedPeerUIDs, false)
	if err != nil {
		return ServerOptions{}, err
	}

	peerGIDs, err := resolvePeerPrincipals(allowedPeerGIDs, true)
	if err != nil {
		return ServerOptions{}, err
	}

	return ServerOptions{
		SocketPath:       socketPath,
		SocketMode:       os.FileMode(parsedSocketMode),
		SocketGroupID:    socketGroupID,
		AllowedPeerUIDs:  peerUIDs,
		AllowedPeerGIDs:  peerGIDs,
		OperationTimeout: operationTimeout,
	}, nil
}

func NewServer(options ServerOptions) *Server {
	return &Server{
		socketPath:       options.SocketPath,
		socketMode:       options.SocketMode,
		socketGroupID:    options.SocketGroupID,
		allowedPeerUIDs:  options.AllowedPeerUIDs,
		allowedPeerGIDs:  options.AllowedPeerGIDs,
		operationTimeout: options.OperationTimeout,
	}
}

func (s *Server) ListenAndServe() error {
	if err := os.MkdirAll(filepath.Dir(s.socketPath), 0o750); err != nil {
		return err
	}

	_ = os.Remove(s.socketPath)

	listener, err := net.Listen("unix", s.socketPath)
	if err != nil {
		return err
	}

	if err := os.Chmod(s.socketPath, s.socketMode); err != nil {
		return err
	}

	if s.socketGroupID >= 0 {
		if err := os.Chown(s.socketPath, -1, s.socketGroupID); err != nil {
			return err
		}
	}

	for {
		connection, err := listener.Accept()
		if err != nil {
			return err
		}

		go s.handleConnection(connection)
	}
}

func (s *Server) handleConnection(connection net.Conn) {
	defer connection.Close()

	if err := s.authorizeConnection(connection); err != nil {
		s.writeResponse(connection, response{Success: false, Error: &responseError{Message: err.Error()}})
		return
	}

	reader := bufio.NewReaderSize(connection, 64*1024)
	line, err := reader.ReadSlice('\n')
	if errors.Is(err, bufio.ErrBufferFull) {
		s.writeResponse(connection, response{Success: false, Error: &responseError{Message: "request exceeded 64 KiB limit"}})
		return
	}
	if err != nil {
		s.writeResponse(connection, response{Success: false, Error: &responseError{Message: err.Error()}})
		return
	}

	var request request
	if err := json.Unmarshal(line, &request); err != nil {
		s.writeResponse(connection, response{Success: false, Error: &responseError{Message: err.Error()}})
		return
	}

	data, err := s.dispatch(request)
	if err != nil {
		s.writeResponse(connection, response{Success: false, Error: &responseError{Message: err.Error()}})
		return
	}

	s.writeResponse(connection, response{Success: true, Data: data})
}

func (s *Server) authorizeConnection(connection net.Conn) error {
	unixConnection, ok := connection.(*net.UnixConn)
	if !ok {
		return errors.New("agent only accepts unix socket connections")
	}

	rawConnection, err := unixConnection.SyscallConn()
	if err != nil {
		return err
	}

	var credentials *syscall.Ucred
	var controlErr error

	if err := rawConnection.Control(func(fd uintptr) {
		credentials, controlErr = syscall.GetsockoptUcred(int(fd), syscall.SOL_SOCKET, syscall.SO_PEERCRED)
	}); err != nil {
		return err
	}

	if controlErr != nil {
		return controlErr
	}

	if _, ok := s.allowedPeerUIDs[credentials.Uid]; ok {
		return nil
	}

	if _, ok := s.allowedPeerGIDs[credentials.Gid]; ok {
		return nil
	}

	return fmt.Errorf("unauthorized peer uid=%d gid=%d", credentials.Uid, credentials.Gid)
}

func (s *Server) writeResponse(connection net.Conn, payload response) {
	encoder := json.NewEncoder(connection)
	_ = encoder.Encode(payload)
}

func (s *Server) dispatch(request request) (map[string]interface{}, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	switch request.Operation {
	case "namespace.apply":
		return s.applyNamespace(request.Parameters)
	case "routing.apply":
		return s.applyRouting(request.Parameters)
	case "namespace.observe":
		return s.observeNamespace(request.Parameters)
	case "routing.observe":
		return s.observeRouting(request.Parameters)
	case "namespace.destroy":
		return s.destroyNamespace(request.Parameters)
	default:
		return nil, fmt.Errorf("unsupported operation: %s", request.Operation)
	}
}

func (s *Server) applyNamespace(parameters map[string]interface{}) (map[string]interface{}, error) {
	namespaceName, interfaceName, managementCIDR, err := validateApplyParameters(parameters)
	if err != nil {
		return nil, err
	}

	return s.applyRouting(map[string]interface{}{
		"name": namespaceName,
		"desired_configuration": routingDesiredConfiguration{
			Namespace: namespaceName,
			Management: routingManagement{
				Interface: interfaceName,
				CIDR:      managementCIDR,
			},
		},
	})
}

func (s *Server) applyRouting(parameters map[string]interface{}) (map[string]interface{}, error) {
	namespaceName, desiredConfiguration, err := validateRoutingApplyParameters(parameters)
	if err != nil {
		return nil, err
	}

	if err := s.ensureNamespace(namespaceName); err != nil {
		return nil, err
	}

	if err := s.reconcileManagedInterfaces(namespaceName, desiredConfiguration); err != nil {
		return nil, err
	}

	if err := s.reconcileBridgeVLANs(namespaceName, desiredConfiguration); err != nil {
		return nil, err
	}

	if err := s.reconcileAddresses(namespaceName, desiredConfiguration); err != nil {
		return nil, err
	}

	if err := s.reconcileRoutes(namespaceName, desiredConfiguration); err != nil {
		return nil, err
	}

	if err := s.reconcileForwarding(namespaceName, desiredConfiguration); err != nil {
		return nil, err
	}

	if err := s.reconcileFirewall(namespaceName, desiredConfiguration); err != nil {
		return nil, err
	}

	return s.observeRouting(map[string]interface{}{"name": namespaceName})
}

func (s *Server) observeNamespace(parameters map[string]interface{}) (map[string]interface{}, error) {
	return s.observeRouting(parameters)
}

func (s *Server) observeRouting(parameters map[string]interface{}) (map[string]interface{}, error) {
	namespaceName, err := validateNamespace(parameters)
	if err != nil {
		return nil, err
	}

	if !s.namespaceExists(namespaceName) {
		return nil, fmt.Errorf("namespace %s does not exist", namespaceName)
	}

	links, err := s.run("ip", "-n", namespaceName, "-j", "-details", "link", "show")
	if err != nil {
		return nil, err
	}

	addresses, err := s.run("ip", "-n", namespaceName, "-j", "addr", "show")
	if err != nil {
		return nil, err
	}

	routes, err := s.run("ip", "-n", namespaceName, "-j", "route", "show")
	if err != nil {
		return nil, err
	}

	bridgeVlans, err := s.runInNamespace(namespaceName, "bridge", "-j", "vlan", "show")
	if err != nil {
		return nil, err
	}

	firewall, err := s.runInNamespace(namespaceName, "nft", "-j", "list", "ruleset")
	if err != nil {
		return nil, err
	}

	ipv4Forwarding, err := s.runInNamespace(namespaceName, "sysctl", "-n", "net.ipv4.ip_forward")
	if err != nil {
		return nil, err
	}

	return map[string]interface{}{
		"namespace":      namespaceName,
		"links":          json.RawMessage(links),
		"addresses":      json.RawMessage(addresses),
		"routes":         json.RawMessage(routes),
		"bridge_vlans":   json.RawMessage(bridgeVlans),
		"firewall":       json.RawMessage(firewall),
		"forwarding":     map[string]interface{}{"ipv4": strings.TrimSpace(string(ipv4Forwarding))},
		"observed_at":    time.Now().UTC().Format(time.RFC3339),
		"observation_op": "routing.observe",
	}, nil
}

func (s *Server) destroyNamespace(parameters map[string]interface{}) (map[string]interface{}, error) {
	namespaceName, err := validateNamespace(parameters)
	if err != nil {
		return nil, err
	}

	if !s.namespaceExists(namespaceName) {
		return map[string]interface{}{"namespace": namespaceName, "deleted": false}, nil
	}

	if _, err := s.run("ip", "netns", "delete", namespaceName); err != nil {
		return nil, err
	}

	return map[string]interface{}{"namespace": namespaceName, "deleted": true}, nil
}

func (s *Server) ensureNamespace(namespaceName string) error {
	if s.namespaceExists(namespaceName) {
		_, err := s.run("ip", "-n", namespaceName, "link", "set", "lo", "up")
		return err
	}

	if _, err := s.run("ip", "netns", "add", namespaceName); err != nil {
		return err
	}

	_, err := s.run("ip", "-n", namespaceName, "link", "set", "lo", "up")
	return err
}

func (s *Server) namespaceExists(namespaceName string) bool {
	_, err := os.Stat(filepath.Join("/run/netns", namespaceName))
	return err == nil
}

func (s *Server) interfaceExists(namespaceName string, interfaceName string) bool {
	_, err := s.run("ip", "-n", namespaceName, "link", "show", "dev", interfaceName)
	return err == nil
}

func (s *Server) run(command string, arguments ...string) ([]byte, error) {
	context, cancel := context.WithTimeout(context.Background(), s.operationTimeout)
	defer cancel()

	cmd := exec.CommandContext(context, command, arguments...)
	cmd.SysProcAttr = &syscall.SysProcAttr{}

	output, err := cmd.CombinedOutput()
	if err != nil {
		return nil, fmt.Errorf("%s %v failed: %w (%s)", command, arguments, err, string(output))
	}

	return output, nil
}

func (s *Server) runInNamespace(namespaceName string, command string, arguments ...string) ([]byte, error) {
	namespaceArguments := append([]string{"netns", "exec", namespaceName, command}, arguments...)

	return s.run("ip", namespaceArguments...)
}

func (s *Server) runWithInput(input string, command string, arguments ...string) ([]byte, error) {
	context, cancel := context.WithTimeout(context.Background(), s.operationTimeout)
	defer cancel()

	cmd := exec.CommandContext(context, command, arguments...)
	cmd.SysProcAttr = &syscall.SysProcAttr{}
	cmd.Stdin = strings.NewReader(input)

	output, err := cmd.CombinedOutput()
	if err != nil {
		return nil, fmt.Errorf("%s %v failed: %w (%s)", command, arguments, err, string(output))
	}

	return output, nil
}

func (s *Server) reconcileManagedInterfaces(namespaceName string, desiredConfiguration routingDesiredConfiguration) error {
	desiredInterfaces := map[string]routingInterface{
		desiredConfiguration.Management.Interface: {
			Name:  desiredConfiguration.Management.Interface,
			Type:  "management",
			State: "up",
		},
	}

	for _, interfaceDefinition := range desiredConfiguration.Interfaces {
		desiredInterfaces[interfaceDefinition.Name] = interfaceDefinition
	}

	links, err := s.run("ip", "-n", namespaceName, "-j", "-details", "link", "show")
	if err != nil {
		return err
	}

	var observedLinks []map[string]interface{}
	if err := json.Unmarshal(links, &observedLinks); err != nil {
		return err
	}

	for _, link := range observedLinks {
		interfaceName, _ := link["ifname"].(string)
		if interfaceName == "" || interfaceName == "lo" {
			continue
		}

		if _, keep := desiredInterfaces[interfaceName]; keep {
			continue
		}

		linkInfo, _ := link["linkinfo"].(map[string]interface{})
		kind, _ := linkInfo["info_kind"].(string)

		if kind == "bridge" || kind == "vlan" || kind == "dummy" {
			if _, err := s.run("ip", "-n", namespaceName, "link", "delete", "dev", interfaceName); err != nil && !strings.Contains(err.Error(), "Cannot find device") {
				return err
			}
		}
	}

	if !s.interfaceExists(namespaceName, desiredConfiguration.Management.Interface) {
		if _, err := s.run("ip", "-n", namespaceName, "link", "add", desiredConfiguration.Management.Interface, "type", "dummy"); err != nil {
			return err
		}
	}

	for _, interfaceDefinition := range desiredConfiguration.Interfaces {
		if interfaceDefinition.Type == "vlan" || interfaceDefinition.Name == desiredConfiguration.Management.Interface {
			continue
		}

		if err := s.ensureManagedInterface(namespaceName, interfaceDefinition); err != nil {
			return err
		}
	}

	for _, interfaceDefinition := range desiredConfiguration.Interfaces {
		if interfaceDefinition.Type != "vlan" {
			continue
		}

		if err := s.ensureManagedInterface(namespaceName, interfaceDefinition); err != nil {
			return err
		}
	}

	for _, interfaceDefinition := range desiredInterfaces {
		if interfaceDefinition.Master != "" {
			if _, err := s.run("ip", "-n", namespaceName, "link", "set", "dev", interfaceDefinition.Name, "master", interfaceDefinition.Master); err != nil {
				return err
			}
		}

		if interfaceDefinition.MTU > 0 {
			if _, err := s.run("ip", "-n", namespaceName, "link", "set", "dev", interfaceDefinition.Name, "mtu", strconv.Itoa(interfaceDefinition.MTU)); err != nil {
				return err
			}
		}

		state := interfaceDefinition.State
		if state == "" {
			state = "up"
		}

		if _, err := s.run("ip", "-n", namespaceName, "link", "set", "dev", interfaceDefinition.Name, state); err != nil {
			return err
		}
	}

	return nil
}

func (s *Server) ensureManagedInterface(namespaceName string, interfaceDefinition routingInterface) error {
	if s.interfaceExists(namespaceName, interfaceDefinition.Name) {
		return nil
	}

	switch interfaceDefinition.Type {
	case "bridge":
		_, err := s.run("ip", "-n", namespaceName, "link", "add", interfaceDefinition.Name, "type", "bridge")
		return err
	case "dummy":
		_, err := s.run("ip", "-n", namespaceName, "link", "add", interfaceDefinition.Name, "type", "dummy")
		return err
	case "ether", "management":
		if !s.interfaceExists(namespaceName, interfaceDefinition.Name) {
			return fmt.Errorf("interface %s does not exist in namespace %s", interfaceDefinition.Name, namespaceName)
		}

		return nil
	case "vlan":
		if interfaceDefinition.Parent == "" {
			return fmt.Errorf("vlan interface %s requires a parent interface", interfaceDefinition.Name)
		}

		_, err := s.run(
			"ip", "-n", namespaceName, "link", "add",
			"link", interfaceDefinition.Parent,
			"name", interfaceDefinition.Name,
			"type", "vlan", "id", strconv.Itoa(interfaceDefinition.VLANID),
		)
		return err
	default:
		return fmt.Errorf("unsupported interface type: %s", interfaceDefinition.Type)
	}
}

func (s *Server) reconcileBridgeVLANs(namespaceName string, desiredConfiguration routingDesiredConfiguration) error {
	for _, bridgeVLAN := range desiredConfiguration.BridgeVLANs {
		arguments := []string{"vlan", "add", "dev", bridgeVLAN.Device, "vid", strconv.Itoa(bridgeVLAN.VID)}
		if bridgeVLAN.PVID {
			arguments = append(arguments, "pvid")
		}
		if bridgeVLAN.Untagged {
			arguments = append(arguments, "untagged")
		}
		if bridgeVLAN.Self {
			arguments = append(arguments, "self")
		}

		if _, err := s.runInNamespace(namespaceName, "bridge", arguments...); err != nil && !strings.Contains(err.Error(), "File exists") {
			return err
		}
	}

	return nil
}

func (s *Server) reconcileAddresses(namespaceName string, desiredConfiguration routingDesiredConfiguration) error {
	interfacesToFlush := map[string]struct{}{
		desiredConfiguration.Management.Interface: {},
	}

	for _, address := range desiredConfiguration.Addresses {
		interfacesToFlush[address.Interface] = struct{}{}
	}

	for interfaceName := range interfacesToFlush {
		if _, err := s.run("ip", "-n", namespaceName, "addr", "flush", "dev", interfaceName); err != nil {
			return err
		}
	}

	if _, err := s.run("ip", "-n", namespaceName, "addr", "add", desiredConfiguration.Management.CIDR, "dev", desiredConfiguration.Management.Interface); err != nil {
		return err
	}

	for _, address := range desiredConfiguration.Addresses {
		if _, err := s.run("ip", "-n", namespaceName, "addr", "add", address.CIDR, "dev", address.Interface); err != nil {
			return err
		}
	}

	return nil
}

func (s *Server) reconcileRoutes(namespaceName string, desiredConfiguration routingDesiredConfiguration) error {
	if _, err := s.run("ip", "-n", namespaceName, "route", "flush", "proto", "static"); err != nil {
		return err
	}

	for _, route := range desiredConfiguration.Routes {
		arguments := []string{"-n", namespaceName, "route", "add"}
		if route.Destination == "default" {
			arguments = append(arguments, "default")
		} else {
			arguments = append(arguments, route.Destination)
		}
		if route.Gateway != "" {
			arguments = append(arguments, "via", route.Gateway)
		}
		if route.Device != "" {
			arguments = append(arguments, "dev", route.Device)
		}
		if route.Metric > 0 {
			arguments = append(arguments, "metric", strconv.Itoa(route.Metric))
		}

		if _, err := s.run("ip", arguments...); err != nil {
			return err
		}
	}

	return nil
}

func (s *Server) reconcileForwarding(namespaceName string, desiredConfiguration routingDesiredConfiguration) error {
	enabled := desiredConfiguration.Forwarding.IPv4 || len(desiredConfiguration.Firewall.NAT) > 0
	value := "0"
	if enabled {
		value = "1"
	}

	_, err := s.runInNamespace(namespaceName, "sysctl", "-w", "net.ipv4.ip_forward="+value)
	return err
}

func (s *Server) reconcileFirewall(namespaceName string, desiredConfiguration routingDesiredConfiguration) error {
	if len(desiredConfiguration.Firewall.Rules) == 0 && len(desiredConfiguration.Firewall.NAT) == 0 {
		_, err := s.runInNamespace(namespaceName, "nft", "flush", "ruleset")
		return err
	}

	lines := []string{"flush ruleset"}

	if len(desiredConfiguration.Firewall.Rules) > 0 {
		lines = append(lines,
			"table inet filter {",
			"  chain input { type filter hook input priority filter; policy accept; }",
			"  chain forward { type filter hook forward priority filter; policy accept; }",
			"  chain output { type filter hook output priority filter; policy accept; }",
			"}",
		)

		for _, rule := range desiredConfiguration.Firewall.Rules {
			lines = append(lines, buildFilterRule(rule))
		}
	}

	if len(desiredConfiguration.Firewall.NAT) > 0 {
		lines = append(lines,
			"table ip nat {",
			"  chain prerouting { type nat hook prerouting priority dstnat; policy accept; }",
			"  chain postrouting { type nat hook postrouting priority srcnat; policy accept; }",
			"}",
		)

		for _, rule := range desiredConfiguration.Firewall.NAT {
			lines = append(lines, buildNATRule(rule))
		}
	}

	_, err := s.runWithInput(strings.Join(lines, "\n")+"\n", "ip", "netns", "exec", namespaceName, "nft", "-f", "-")
	return err
}

func buildFilterRule(rule routingFirewallRule) string {
	parts := []string{"add", "rule", "inet", "filter", rule.Chain}
	parts = append(parts, ruleMatchFragments(rule.Protocol, rule.Source, rule.Destination, rule.InInterface, rule.OutInterface)...)
	parts = append(parts, rule.Action)
	return strings.Join(parts, " ")
}

func buildNATRule(rule routingNATRule) string {
	parts := []string{"add", "rule", "ip", "nat", rule.Chain}
	parts = append(parts, ruleMatchFragments(rule.Protocol, rule.Source, rule.Destination, rule.InInterface, rule.OutInterface)...)

	switch rule.Action {
	case "masquerade":
		parts = append(parts, "masquerade")
	case "snat":
		parts = append(parts, "snat", "to", rule.ToAddress)
	case "dnat":
		parts = append(parts, "dnat", "to", rule.ToAddress)
	}

	return strings.Join(parts, " ")
}

func ruleMatchFragments(protocol string, source string, destination string, inInterface string, outInterface string) []string {
	fragments := []string{}

	if protocol != "" {
		fragments = append(fragments, "meta", "l4proto", protocol)
	}
	if source != "" {
		fragments = append(fragments, "ip", "saddr", source)
	}
	if destination != "" {
		fragments = append(fragments, "ip", "daddr", destination)
	}
	if inInterface != "" {
		fragments = append(fragments, "iifname", fmt.Sprintf("\"%s\"", inInterface))
	}
	if outInterface != "" {
		fragments = append(fragments, "oifname", fmt.Sprintf("\"%s\"", outInterface))
	}

	return fragments
}

func validateApplyParameters(parameters map[string]interface{}) (string, string, string, error) {
	namespaceName, err := requireString(parameters, "name")
	if err != nil {
		return "", "", "", err
	}

	interfaceName, err := requireString(parameters, "interface_name")
	if err != nil {
		return "", "", "", err
	}

	managementCIDR, err := requireString(parameters, "management_cidr")
	if err != nil {
		return "", "", "", err
	}

	if !namePattern.MatchString(namespaceName) {
		return "", "", "", errors.New("invalid namespace name")
	}

	if !interfacePattern.MatchString(interfaceName) {
		return "", "", "", errors.New("invalid interface name")
	}

	if _, _, err := net.ParseCIDR(managementCIDR); err != nil {
		return "", "", "", errors.New("invalid management CIDR")
	}

	return namespaceName, interfaceName, managementCIDR, nil
}

func validateRoutingApplyParameters(parameters map[string]interface{}) (string, routingDesiredConfiguration, error) {
	namespaceName, err := validateNamespace(parameters)
	if err != nil {
		return "", routingDesiredConfiguration{}, err
	}

	desiredConfigurationValue, ok := parameters["desired_configuration"]
	if !ok {
		return "", routingDesiredConfiguration{}, errors.New("missing parameter: desired_configuration")
	}

	desiredConfigurationBytes, err := json.Marshal(desiredConfigurationValue)
	if err != nil {
		return "", routingDesiredConfiguration{}, err
	}

	var desiredConfiguration routingDesiredConfiguration
	if err := json.Unmarshal(desiredConfigurationBytes, &desiredConfiguration); err != nil {
		return "", routingDesiredConfiguration{}, err
	}

	if desiredConfiguration.Namespace == "" {
		desiredConfiguration.Namespace = namespaceName
	}

	if desiredConfiguration.Namespace != namespaceName {
		return "", routingDesiredConfiguration{}, errors.New("desired configuration namespace mismatch")
	}

	if !interfacePattern.MatchString(desiredConfiguration.Management.Interface) {
		return "", routingDesiredConfiguration{}, errors.New("invalid management interface name")
	}

	if _, _, err := net.ParseCIDR(desiredConfiguration.Management.CIDR); err != nil {
		return "", routingDesiredConfiguration{}, errors.New("invalid management CIDR")
	}

	for _, interfaceDefinition := range desiredConfiguration.Interfaces {
		if !interfacePattern.MatchString(interfaceDefinition.Name) {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid interface name: %s", interfaceDefinition.Name)
		}
		if interfaceDefinition.Master != "" && !interfacePattern.MatchString(interfaceDefinition.Master) {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid interface master: %s", interfaceDefinition.Master)
		}
		if interfaceDefinition.Parent != "" && !interfacePattern.MatchString(interfaceDefinition.Parent) {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid interface parent: %s", interfaceDefinition.Parent)
		}
		if interfaceDefinition.Type == "vlan" && (interfaceDefinition.VLANID < 1 || interfaceDefinition.VLANID > 4094) {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid vlan id for interface %s", interfaceDefinition.Name)
		}
	}

	for _, address := range desiredConfiguration.Addresses {
		if !interfacePattern.MatchString(address.Interface) {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid address interface: %s", address.Interface)
		}
		if _, _, err := net.ParseCIDR(address.CIDR); err != nil {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid address cidr: %s", address.CIDR)
		}
	}

	for _, route := range desiredConfiguration.Routes {
		if route.Destination != "default" {
			if _, _, err := net.ParseCIDR(route.Destination); err != nil {
				return "", routingDesiredConfiguration{}, fmt.Errorf("invalid route destination: %s", route.Destination)
			}
		}
		if route.Gateway != "" && net.ParseIP(route.Gateway) == nil {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid route gateway: %s", route.Gateway)
		}
		if route.Device != "" && !interfacePattern.MatchString(route.Device) {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid route device: %s", route.Device)
		}
	}

	for _, bridgeVLAN := range desiredConfiguration.BridgeVLANs {
		if !interfacePattern.MatchString(bridgeVLAN.Device) {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid bridge vlan device: %s", bridgeVLAN.Device)
		}
		if bridgeVLAN.VID < 1 || bridgeVLAN.VID > 4094 {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid bridge vlan vid for device %s", bridgeVLAN.Device)
		}
	}

	for _, rule := range desiredConfiguration.Firewall.Rules {
		if err := validateFirewallMatch(rule.Protocol, rule.Source, rule.Destination, rule.InInterface, rule.OutInterface); err != nil {
			return "", routingDesiredConfiguration{}, err
		}
	}

	for _, rule := range desiredConfiguration.Firewall.NAT {
		if err := validateFirewallMatch(rule.Protocol, rule.Source, rule.Destination, rule.InInterface, rule.OutInterface); err != nil {
			return "", routingDesiredConfiguration{}, err
		}
		if (rule.Action == "snat" || rule.Action == "dnat") && net.ParseIP(rule.ToAddress) == nil {
			return "", routingDesiredConfiguration{}, fmt.Errorf("invalid nat to_address for chain %s", rule.Chain)
		}
	}

	return namespaceName, desiredConfiguration, nil
}

func validateFirewallMatch(protocol string, source string, destination string, inInterface string, outInterface string) error {
	if source != "" && source != "default" {
		if _, _, err := net.ParseCIDR(source); err != nil {
			return fmt.Errorf("invalid firewall source: %s", source)
		}
	}
	if destination != "" && destination != "default" {
		if _, _, err := net.ParseCIDR(destination); err != nil {
			return fmt.Errorf("invalid firewall destination: %s", destination)
		}
	}
	if inInterface != "" && !interfacePattern.MatchString(inInterface) {
		return fmt.Errorf("invalid firewall input interface: %s", inInterface)
	}
	if outInterface != "" && !interfacePattern.MatchString(outInterface) {
		return fmt.Errorf("invalid firewall output interface: %s", outInterface)
	}
	if protocol != "" {
		switch protocol {
		case "tcp", "udp", "icmp":
		default:
			return fmt.Errorf("invalid firewall protocol: %s", protocol)
		}
	}

	return nil
}

func validateNamespace(parameters map[string]interface{}) (string, error) {
	namespaceName, err := requireString(parameters, "name")
	if err != nil {
		return "", err
	}

	if !namePattern.MatchString(namespaceName) {
		return "", errors.New("invalid namespace name")
	}

	return namespaceName, nil
}

func requireString(parameters map[string]interface{}, key string) (string, error) {
	value, ok := parameters[key]
	if !ok {
		return "", fmt.Errorf("missing parameter: %s", key)
	}

	stringValue, ok := value.(string)
	if !ok || stringValue == "" {
		return "", fmt.Errorf("invalid parameter: %s", key)
	}

	return stringValue, nil
}

func resolveGroupID(group string) (int, error) {
	group = strings.TrimSpace(group)
	if group == "" {
		return -1, nil
	}

	if numericGroupID, err := strconv.Atoi(group); err == nil {
		return numericGroupID, nil
	}

	resolvedGroup, err := user.LookupGroup(group)
	if err != nil {
		return 0, err
	}

	groupID, err := strconv.Atoi(resolvedGroup.Gid)
	if err != nil {
		return 0, err
	}

	return groupID, nil
}

func resolvePeerPrincipals(value string, groups bool) (map[uint32]struct{}, error) {
	resolvedPrincipals := make(map[uint32]struct{})

	for _, entry := range strings.Split(value, ",") {
		entry = strings.TrimSpace(entry)
		if entry == "" {
			continue
		}

		if numericValue, err := strconv.ParseUint(entry, 10, 32); err == nil {
			resolvedPrincipals[uint32(numericValue)] = struct{}{}
			continue
		}

		var numericIdentifier string
		var err error

		if groups {
			var resolvedGroup *user.Group
			resolvedGroup, err = user.LookupGroup(entry)
			if err == nil {
				numericIdentifier = resolvedGroup.Gid
			}
		} else {
			var resolvedUser *user.User
			resolvedUser, err = user.Lookup(entry)
			if err == nil {
				numericIdentifier = resolvedUser.Uid
			}
		}

		if err != nil {
			return nil, err
		}

		numericValue, err := strconv.ParseUint(numericIdentifier, 10, 32)
		if err != nil {
			return nil, err
		}

		resolvedPrincipals[uint32(numericValue)] = struct{}{}
	}

	return resolvedPrincipals, nil
}
