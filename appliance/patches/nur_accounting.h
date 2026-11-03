/* Appliance accounting handoff. No shell, no daemon dependency, no secret argv.
 * put exits successfully only after durable storage; remote ACK is asynchronous.
 */
#include <spawn.h>
#include <sys/wait.h>
#include <signal.h>
#include <fcntl.h>
#include <sys/socket.h>
extern char **environ;
static int nur_acct_queue(struct rad_req_t *req)
{
 int fd[2], status, rc, i;
 pid_t child;
 posix_spawn_file_actions_t actions;
 unsigned char zero[16] = {0};
 char *argv[] = {"/usr/sbin/nur-accounting", "put", NULL};
 if (rad_packet_build(req->pack, zero) || req->pack->len > 4096)
  return -1;
 if (socketpair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0, fd)) return -1;
 posix_spawn_file_actions_init(&actions);
 posix_spawn_file_actions_adddup2(&actions, fd[0], STDIN_FILENO);
 posix_spawn_file_actions_addclose(&actions, fd[1]);
 rc = posix_spawn(&child, argv[0], &actions, NULL, argv, environ);
 posix_spawn_file_actions_destroy(&actions);
 close(fd[0]);
 if (rc) { close(fd[1]); return -1; }
 /* <= PIPE_BUF; one atomic write. The helper reads only this bounded packet. */
 do { rc = send(fd[1], req->pack->buf, req->pack->len, MSG_NOSIGNAL); } while (rc < 0 && errno == EINTR);
 close(fd[1]);
 for (i = 0; i < 500; i++) {
  pid_t done = waitpid(child, &status, WNOHANG);
  if (done == child)
   return rc == req->pack->len && WIFEXITED(status) && WEXITSTATUS(status) == 0 ? 0 : -1;
  if (done < 0 && errno != EINTR) return -1;
  usleep(10000);
 }
 kill(child, SIGKILL);
 while (waitpid(child, &status, 0) < 0 && errno == EINTR) {}
 return -1;
}
static void nur_acct_timestamp(struct rad_req_t *req)
{
 if (rad_packet_change_int(req->pack, NULL, "Event-Timestamp", time(NULL)))
  rad_packet_add_int(req->pack, NULL, "Event-Timestamp", time(NULL));
}
