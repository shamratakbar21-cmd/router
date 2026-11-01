package main

import (
	"flag"
	"log"
	"time"

	"nurrouteros/agent/internal/agent"
)

func main() {
	socketPath := flag.String("socket", "/var/www/nurrouter.com/nurrouteros/agent/run/nur-agent.sock", "unix socket path")
	socketMode := flag.String("socket-mode", "0660", "octal unix socket mode")
	socketGroup := flag.String("socket-group", "www-data", "unix group name or gid for the socket")
	allowedPeerUIDs := flag.String("allowed-peer-uids", "0", "comma-separated peer user names or uids")
	allowedPeerGIDs := flag.String("allowed-peer-gids", "www-data", "comma-separated peer group names or gids")
	operationTimeout := flag.Duration("operation-timeout", 10*time.Second, "maximum runtime for one privileged command")
	flag.Parse()

	options, err := agent.NewServerOptions(
		*socketPath,
		*socketMode,
		*socketGroup,
		*allowedPeerUIDs,
		*allowedPeerGIDs,
		*operationTimeout,
	)
	if err != nil {
		log.Fatal(err)
	}

	server := agent.NewServer(options)

	if err := server.ListenAndServe(); err != nil {
		log.Fatal(err)
	}
}
