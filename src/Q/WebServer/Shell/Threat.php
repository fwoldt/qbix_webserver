<?php
/**
 * @module Q
 */
/**
 * Commands that can destroy data or take the machine down, recognised in a
 * raw OS command line before it runs. Such a command is not refused, but it
 * needs the control panel password again (sudo-style, see
 * Q_WebServer_Shell_Interpreter::elevate()), and the shell says plainly what
 * the command can do -- the point is that nobody types these out of habit.
 *
 * What counts:
 *   - destructive programs: rm, dd, fdisk and the other partitioners, mkfs,
 *     wipefs, shred, truncate ...
 *   - taking the machine or its services down: shutdown, reboot, halt,
 *     poweroff, init, systemctl stop|restart|..., kill, killall, pkill
 *   - accounts, permissions and firewalls: userdel, passwd, chmod -R,
 *     chown -R, iptables, nft ...
 *   - running code the line does not show: . and source, eval, exec, a shell
 *     or interpreter with -c/-e, a download piped into a shell, base64 -d,
 *     network shells (nc, socat), $(...) and backquotes
 *   - writing to disks and system directories: > /dev/sd*, > /etc/...
 *
 * This reads the line as text, so it is a speed bump for mistakes, not a
 * sandbox: the raw OS tier itself stays off unless Q.shell.allowSystem says
 * otherwise.
 *
 * @class Q_WebServer_Shell_Threat
 * @static
 */
class Q_WebServer_Shell_Threat
{
	/** program => what it can do */
	const PROGRAMS = array(
		'rm' => 'deletes files for good; there is no undo',
		'rmdir' => 'removes directories',
		'unlink' => 'deletes a file for good',
		'dd' => 'writes raw bytes over files and whole disks',
		'fdisk' => 'rewrites a disk\'s partition table',
		'sfdisk' => 'rewrites a disk\'s partition table',
		'cfdisk' => 'rewrites a disk\'s partition table',
		'gdisk' => 'rewrites a disk\'s partition table',
		'sgdisk' => 'rewrites a disk\'s partition table',
		'parted' => 'rewrites a disk\'s partitions',
		'mkfs' => 'formats a filesystem, erasing what was on it',
		'mke2fs' => 'formats a filesystem, erasing what was on it',
		'mkswap' => 'formats a device as swap, erasing it',
		'wipefs' => 'erases filesystem signatures from a device',
		'shred' => 'overwrites files so they cannot be recovered',
		'truncate' => 'cuts files down, throwing their contents away',
		'blkdiscard' => 'discards every block of a device',
		'hdparm' => 'changes low-level disk settings; some erase the disk',
		'mount' => 'changes what is mounted where',
		'umount' => 'unmounts filesystems out from under running programs',
		'swapoff' => 'takes swap away from a running system',
		'shutdown' => 'turns the machine off',
		'reboot' => 'restarts the machine',
		'halt' => 'stops the machine',
		'poweroff' => 'turns the machine off',
		'init' => 'changes the system\'s run level',
		'telinit' => 'changes the system\'s run level',
		'kill' => 'stops processes, including this server',
		'killall' => 'stops every process with a name',
		'pkill' => 'stops every process that matches a pattern',
		'userdel' => 'deletes a user account',
		'usermod' => 'changes a user account',
		'groupdel' => 'deletes a group',
		'passwd' => 'changes a password',
		'chpasswd' => 'changes passwords',
		'visudo' => 'changes who may run what as root',
		'iptables' => 'changes the firewall; one rule can lock everyone out',
		'ip6tables' => 'changes the firewall',
		'nft' => 'changes the firewall',
		'ufw' => 'changes the firewall',
		'firewall-cmd' => 'changes the firewall',
		'chattr' => 'changes file attributes, including making files undeletable',
		'setfacl' => 'changes who may read and write files',
		'.' => 'runs every command in a file the line does not show',
		'source' => 'runs every command in a file the line does not show',
		'eval' => 'runs text as commands',
		'exec' => 'replaces the shell with another program',
		'nc' => 'opens raw network connections (a network shell)',
		'ncat' => 'opens raw network connections (a network shell)',
		'netcat' => 'opens raw network connections (a network shell)',
		'socat' => 'relays raw connections (a network shell)',
	);

	/** Programs that are only dangerous with some arguments. */
	const WITH_ARGS = array(
		'systemctl' => array('/\b(stop|restart|disable|mask|kill|isolate|poweroff|reboot|halt|emergency|rescue)\b/', 'stops or changes system services'),
		'service' => array('/\b(stop|restart)\b/', 'stops or restarts a system service'),
		'chmod' => array('/(^|\s)-[a-zA-Z]*R|\b0?00\b|\b0?777\b/', 'changes permissions recursively or to wide open/none'),
		'chown' => array('/(^|\s)-[a-zA-Z]*R/', 'changes owners recursively'),
		'crontab' => array('/(^|\s)-[a-zA-Z]*r/', 'deletes every scheduled job of a user'),
		'find' => array('/\s-(delete|exec|execdir|ok)\b/', 'deletes or runs commands on every file it finds'),
		'rsync' => array('/--delete/', 'deletes files at the destination'),
		'mv' => array('/\s\/(\s|$)|\s\/(etc|boot|usr|bin|sbin|lib|var)\b/', 'moves system directories'),
		'base64' => array('/(^|\s)-[a-zA-Z]*d|--decode/', 'decodes text that can hide commands'),
		'xxd' => array('/(^|\s)-r/', 'turns text back into bytes that can hide programs'),
		'sh' => array('/(^|\s)-c\b/', 'runs a command string in another shell'),
		'bash' => array('/(^|\s)-c\b/', 'runs a command string in another shell'),
		'zsh' => array('/(^|\s)-c\b/', 'runs a command string in another shell'),
		'dash' => array('/(^|\s)-c\b/', 'runs a command string in another shell'),
		'php' => array('/(^|\s)-r\b/', 'runs PHP code given on the line'),
		'python' => array('/(^|\s)-c\b/', 'runs Python code given on the line'),
		'python3' => array('/(^|\s)-c\b/', 'runs Python code given on the line'),
		'perl' => array('/(^|\s)-e\b/', 'runs Perl code given on the line'),
		'ruby' => array('/(^|\s)-e\b/', 'runs Ruby code given on the line'),
		'node' => array('/(^|\s)-e\b/', 'runs JavaScript given on the line'),
	);

	/** Words that only change how the next program runs. */
	const WRAPPERS = array('sudo', 'doas', 'command', 'builtin', 'nice', 'nohup', 'time', 'env', 'xargs', 'busybox',
		'stdbuf', 'ionice', 'timeout', 'setsid', 'unbuffer', 'chroot', 'su', 'runuser', 'watch', 'strace');

	/**
	 * Why a raw OS command line needs the password again.
	 * @method assess
	 * @static
	 * @param {string} $line
	 * @return {array} reasons, each "word: what it can do"; empty when none
	 */
	static function assess($line)
	{
		$line = (string) $line;
		$reasons = array();
		if (preg_match('/\$\(|`/', $line)) $reasons[] = '$(...) or backquotes: runs commands the line does not show';
		if (preg_match('/:\s*\(\s*\)\s*\{/', $line)) $reasons[] = 'a fork bomb pattern: exhausts the machine';
		if (preg_match('/\|\s*(sudo\s+)?(ba|z|da|k)?sh\b/', $line)) $reasons[] = 'piping into a shell: runs whatever the input says';
		if (preg_match('/>{1,2}\s*\/(dev\/(sd|hd|vd|xvd|nvme|mmcblk|disk|mapper)|etc\/|boot\/|usr\/|bin\/|sbin\/|lib)/', $line)) {
			$reasons[] = 'writing into a disk or a system directory';
		}
		foreach (preg_split('/\|\||&&|[;|&\n]/', $line) as $segment) {
			$words = preg_split('/\s+/', trim($segment), -1, PREG_SPLIT_NO_EMPTY);
			while ($words && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0])) array_shift($words);
			if (!$words) continue;
			$at = 0;
			if (in_array(basename($words[0]), self::WRAPPERS, true)) {
				// sudo -u root rm ..., env X=1 dd ..., timeout 5 shred ...: the
				// wrapper's own arguments vary, so look for the program itself.
				$at = null;
				for ($k = 1; $k < count($words); $k++) {
					list($b, $hidden) = self::program($words[$k]);
					if ($hidden || ($b !== '.' && (isset(self::PROGRAMS[$b]) || isset(self::WITH_ARGS[$b]) || preg_match('/^mkfs(\..+)?$/', $b)))) { $at = $k; break; }
				}
				if ($at === null) continue;
			}
			list($prog, $hidden) = self::program($words[$at]);
			if ($hidden) {
				// r\m, r''m, $X, $'\x72m', /bin/r?: the shell would find a program
				// this line does not name, so it cannot be told harmless.
				$reasons[] = $prog . ': the program name is hidden by quotes, backslashes, a variable or a pattern';
				continue;
			}
			$rest = ' ' . implode(' ', array_slice($words, $at + 1));
			if (preg_match('/^mkfs(\..+)?$/', $prog)) $prog = 'mkfs';
			if (isset(self::PROGRAMS[$prog])) $reasons[] = $prog . ': ' . self::PROGRAMS[$prog];
			elseif (isset(self::WITH_ARGS[$prog]) && preg_match(self::WITH_ARGS[$prog][0], $rest)) $reasons[] = $prog . ': ' . self::WITH_ARGS[$prog][1];
		}
		return array_values(array_unique($reasons));
	}

	/**
	 * The program a command word names, as the shell would see it once quotes
	 * and backslashes are gone, and whether the word hides it: quotes or
	 * backslashes inside the name, a variable or command substitution, or a
	 * glob pattern.
	 * @return {array} array(name, hidden)
	 */
	private static function program($word)
	{
		$word = (string) $word;
		$plain = $word;
		// A name wholly inside one pair of quotes ("rm") is not hidden.
		if (preg_match('/^([\'"])([^\'"\\\\]*)\1$/', $word, $m)) $plain = $m[2];
		$name = basename(str_replace(array('\\', '"', "'"), '', $plain));
		$hidden = $plain !== $word ? false : (bool) preg_match('/[\\\\\'"$`*?\[]/', $word);
		return array($name === '' ? $word : $name, $hidden);
	}
}
