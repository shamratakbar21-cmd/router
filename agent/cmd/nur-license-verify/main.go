package main

import (
	"crypto/ed25519"
	"encoding/base64"
	"fmt"
	"os"
)

// Verifies an Ed25519 detached signature over a canonical JSON file.
//
// Usage:
//
//	nur-license-verify <canonical.json> <sig.bin> <pub.raw>
//	nur-license-verify b64decode <in.b64> <out.bin>
func main() {
	if len(os.Args) == 4 && os.Args[1] == "b64decode" {
		in, err := os.ReadFile(os.Args[2])
		if err != nil {
			os.Exit(1)
		}
		out, err := base64.StdEncoding.DecodeString(string(trimSpace(in)))
		if err != nil {
			os.Exit(1)
		}
		if err := os.WriteFile(os.Args[3], out, 0o600); err != nil {
			os.Exit(1)
		}
		os.Exit(0)
	}

	if len(os.Args) != 4 {
		fmt.Fprintln(os.Stderr, "usage: nur-license-verify <canonical.json> <sig.bin> <pub.raw>")
		fmt.Fprintln(os.Stderr, "       nur-license-verify b64decode <in.b64> <out.bin>")
		os.Exit(2)
	}

	message, err := os.ReadFile(os.Args[1])
	if err != nil {
		os.Exit(1)
	}
	sig, err := os.ReadFile(os.Args[2])
	if err != nil {
		os.Exit(1)
	}
	pub, err := os.ReadFile(os.Args[3])
	if err != nil {
		os.Exit(1)
	}

	if len(pub) != ed25519.PublicKeySize || len(sig) != ed25519.SignatureSize {
		os.Exit(1)
	}

	if !ed25519.Verify(ed25519.PublicKey(pub), message, sig) {
		os.Exit(1)
	}
	os.Exit(0)
}

func trimSpace(b []byte) []byte {
	start, end := 0, len(b)
	for start < end {
		c := b[start]
		if c != ' ' && c != '\n' && c != '\r' && c != '\t' {
			break
		}
		start++
	}
	for end > start {
		c := b[end-1]
		if c != ' ' && c != '\n' && c != '\r' && c != '\t' {
			break
		}
		end--
	}
	return b[start:end]
}
