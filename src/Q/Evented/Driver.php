<?php
abstract class Q_Evented_Driver
{
	abstract function onReadable($stream, callable $cb);
	abstract function onWritable($stream, callable $cb);
	abstract function delay($sec, callable $cb);
	abstract function repeat($sec, callable $cb);
	abstract function defer(callable $cb);
	abstract function onSignal($sig, callable $cb);
	abstract function cancel($id);
	abstract function disable($id);
	abstract function enable($id);
	abstract function run();
	abstract function tick($timeout = 0);
	abstract function stop();
	abstract function running();

	/**
	 * Run a timer or deferred callback so that nothing it throws can end the
	 * event loop. The loop is the whole server: one uncaught error in a
	 * periodic job (a stats heartbeat, say) used to stop every listener at
	 * once. The error is logged, once per distinct message, and the loop goes on.
	 */
	static function guard($cb, $kind)
	{
		static $seen = array();
		try {
			$cb();
		} catch (\Throwable $e) {
			$key = get_class($e) . ':' . $e->getMessage();
			if (!isset($seen[$key]) && count($seen) < 200) {
				$seen[$key] = true;
				fwrite(STDERR, sprintf("  %s callback failed, and the server carries on: %s: %s in %s:%d\n",
					$kind, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
			}
		}
	}
}
