#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <grp.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

static int identifier(const char *s) {
    size_t n = strlen(s);
    return n >= 8 && n <= 80 && strspn(s, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-") == n;
}

/* Read each upload as the caller, through non-symlink directory/file handles.
 * Only bounded regular files enter the root-owned snapshot. No caller-writable
 * pathname is used by the privileged runtime after this boundary. */
static int snapshot(const char *source, char *target, uid_t caller) {
    const char *directories[] = {"config", "nurrouter"};
    const char *files[][4] = {{"network", "dhcp", "firewall", NULL}, {"pppoe-shaping.json", "license-entitlements.json", "bras.json", "neighbors.json"}};
    if (!mkdtemp(target)) return 0;
    char path[256];
    snprintf(path, sizeof(path), "%s/etc", target);
    if (mkdir(path, 0700)) return 0;
    if (seteuid(caller)) return 0;
    int base = open(source, O_RDONLY | O_DIRECTORY | O_NOFOLLOW);
    int etc = base < 0 ? -1 : openat(base, "etc", O_RDONLY | O_DIRECTORY | O_NOFOLLOW);
    if (base >= 0) close(base);
    if (etc < 0) { return 0; }
    for (int d = 0; d < 2; d++) {
        int dir = openat(etc, directories[d], O_RDONLY | O_DIRECTORY | O_NOFOLLOW);
        if (dir < 0 && errno != ENOENT) { close(etc); return 0; }
        if (dir < 0) continue;
        if (seteuid(0)) return 0;
        snprintf(path, sizeof(path), "%s/etc/%s", target, directories[d]);
        if (mkdir(path, 0700)) return 0;
        if (seteuid(caller)) return 0;
        for (int f = 0; f < 4 && files[d][f]; f++) {
            int fd = openat(dir, files[d][f], O_RDONLY | O_NOFOLLOW | O_NONBLOCK);
            if (fd < 0 && errno == ENOENT) continue;
            struct stat st;
            if (fd < 0 || fstat(fd, &st) || !S_ISREG(st.st_mode) || st.st_size > 1048576) { close(dir); close(etc); return 0; }
            char *buffer = malloc(1048577);
            if (!buffer) return 0;
            size_t length = 0;
            ssize_t n;
            while (length < 1048577 && (n = read(fd, buffer + length, 1048577 - length)) > 0) length += (size_t)n;
            close(fd);
            if (length > 1048576 || n < 0 || seteuid(0)) { free(buffer); return 0; }
            snprintf(path, sizeof(path), "%s/etc/%s/%s", target, directories[d], files[d][f]);
            int out = open(path, O_WRONLY | O_CREAT | O_EXCL, 0600);
            size_t written = 0;
            while (out >= 0 && written < length) {
                ssize_t count = write(out, buffer + written, length - written);
                if (count <= 0) break;
                written += (size_t)count;
            }
            free(buffer);
            if (out < 0 || close(out) || written != length || seteuid(caller)) return 0;
        }
        close(dir);
    }
    close(etc);
    return seteuid(0) == 0;
}

int main(int argc, char **argv) {
    uid_t caller = getuid();
    if (caller != 0 && caller != 900) return 2;
    if (argc < 3 || strcmp(argv[1], "/usr/sbin/nur-runtimectl")) return 2;
    int apply = !strcmp(argv[2], "apply");
    if (!strcmp(argv[2], "observe")) {
        if (argc != 3) return 2;
    } else if (apply) {
        if (argc != 9 || strcmp(argv[3], "--overlay") || strcmp(argv[5], "--operation") || strcmp(argv[7], "--safe-mode-seconds") || !identifier(argv[6])) return 2;
        const char *prefix = "/tmp/nurrouter-overlay-";
        if (strncmp(argv[4], prefix, strlen(prefix)) || !identifier(argv[4] + strlen(prefix)) || strcmp(argv[4] + strlen(prefix), argv[6])) return 2;
        size_t n = strlen(argv[8]);
        if (!n || n > 5 || strspn(argv[8], "0123456789") != n || strtoul(argv[8], NULL, 10) > 86400) return 2;
    } else if (!strcmp(argv[2], "confirm") || !strcmp(argv[2], "expire")) {
        if (argc != 5 || strcmp(argv[3], "--operation") || !identifier(argv[4])) return 2;
    } else return 2;
    if (clearenv() || setenv("PATH", "/usr/sbin:/usr/bin:/sbin:/bin", 1)) return 3;
    umask(077);
    if (chdir("/")) return 3;
    char stage[] = "/tmp/nur-root-overlay-XXXXXX";
    if (apply) {
        if (!snapshot(argv[4], stage, caller)) {
            fprintf(stderr, "nur-mgmt-elev: unsafe or unreadable upload\n");
            return 4;
        }
        argv[4] = stage;
    }
    if (setgroups(0, NULL) || setgid(0) || setuid(0)) return 3;
    execv(argv[1], &argv[1]);
    fprintf(stderr, "nur-mgmt-elev: exec failed: %s\n", strerror(errno));
    return 127;
}
