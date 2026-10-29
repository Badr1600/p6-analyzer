<?php

namespace TeckFuture\P6Analyzer\Support;

/**
 * Reads the package's `p6-analyzer.*` configuration.
 *
 * The analyzers also run outside a booted Laravel application (standalone CLI
 * usage): the Illuminate helpers are autoloaded, but the container behind
 * config() has no "config" binding and resolving it throws. A read that cannot
 * be resolved therefore falls back to the caller's default instead of failing.
 */
final class Config
{
    /**
     * Get a `p6-analyzer.*` config value.
     *
     * @param  string  $key  The key below `p6-analyzer.`, in dot notation (e.g. 'evm.default_method').
     * @param  mixed  $default  Returned when the key is not set or config cannot be resolved.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            if (function_exists('config')) {
                return config("p6-analyzer.{$key}", $default);
            }
        } catch (\Throwable) {
            // Framework helpers autoloaded without a booted application
            // (standalone CLI usage) — fall through to the default.
        }

        return $default;
    }
}
