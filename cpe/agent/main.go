package main

import (
	"encoding/json"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"time"
)

type capability struct {
	Available bool   `json:"available"`
	Detail    string `json:"detail,omitempty"`
}

type status struct {
	Product      string                `json:"product"`
	Mode         string                `json:"mode"`
	Hostname     string                `json:"hostname"`
	Kernel       string                `json:"kernel"`
	Capabilities map[string]capability `json:"capabilities"`
	Management   string                `json:"management"`
}

func commandExists(name string) bool {
	_, err := exec.LookPath(name)
	return err == nil
}

func readFile(path string) string {
	b, err := os.ReadFile(path)
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

func probe() status {
	host, _ := os.Hostname()
	kernel := readFile("/proc/sys/kernel/osrelease")
	caps := map[string]capability{
		"ubus":        {Available: commandExists("ubus")},
		"uci":         {Available: commandExists("uci")},
		"iproute2":    {Available: commandExists("ip")},
		"nftables":    {Available: commandExists("nft")},
		"hostapd":     {Available: commandExists("hostapd")},
		"iw":          {Available: commandExists("iw")},
		"cwmp_client": {Available: commandExists("cwmp") || commandExists("easycwmp")},
	}
	mode := "program"
	if caps["ubus"].Available && caps["uci"].Available {
		mode = "openwrt-program"
	}
	return status{
		Product: "NetPulse CPE OS", Mode: mode, Hostname: host, Kernel: kernel,
		Capabilities: caps, Management: "read-only-local-probe",
	}
}

func main() {
	socket := os.Getenv("NETPULSE_CPE_SOCKET")
	if socket == "" {
		socket = "/run/netpulse-cpe/agent.sock"
	}
	if err := os.MkdirAll("/run/netpulse-cpe", 0750); err != nil {
		log.Fatal(err)
	}
	_ = os.Remove(socket)
	listener, err := net.Listen("unix", socket)
	if err != nil {
		log.Fatal(err)
	}
	defer listener.Close()
	if err := os.Chmod(socket, 0660); err != nil {
		log.Fatal(err)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/v1/health", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		_ = json.NewEncoder(w).Encode(map[string]string{
			"status": "ok", "time": time.Now().UTC().Format(time.RFC3339),
		})
	})
	mux.HandleFunc("/v1/capabilities", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		_ = json.NewEncoder(w).Encode(probe())
	})
	server := &http.Server{Handler: mux, ReadHeaderTimeout: 3 * time.Second}
	log.Printf("NetPulse CPE agent listening on restricted Unix socket %s", socket)
	log.Fatal(server.Serve(listener))
}
