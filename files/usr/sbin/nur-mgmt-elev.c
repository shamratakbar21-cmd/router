#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

static int allowed(int argc, char **argv) {
    if (argc < 2) return 0;
    if (strcmp(argv[1], "/usr/sbin/nur-runtimectl") != 0) return 0;
    if (argc == 2) return 0;
    if (strcmp(argv[2], "observe") == 0 && argc == 3) return 1;
    if (strcmp(argv[2], "apply") == 0) return 1;
    if (strcmp(argv[2], "confirm") == 0) return 1;
    if (strcmp(argv[2], "expire") == 0) return 1;
    return 0;
}

int main(int argc, char **argv) {
    if (!allowed(argc, argv)) {
        fprintf(stderr, "nur-mgmt-elev: command not permitted\n");
        return 2;
    }
    if (setgid(0) != 0 || setuid(0) != 0) {
        fprintf(stderr, "nur-mgmt-elev: privilege elevation failed: %s\n", strerror(errno));
        return 3;
    }
    execv(argv[1], &argv[1]);
    fprintf(stderr, "nur-mgmt-elev: exec failed: %s\n", strerror(errno));
    return 127;
}
