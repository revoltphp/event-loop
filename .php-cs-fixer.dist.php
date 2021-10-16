<?php

namespace Revolt;

use PhpCsFixer\Config as PhpCsFixerConfig;

final class Config extends PhpCsFixerConfig
{
    private string $src;

    public function __construct()
    {
        parent::__construct('Revolt');

        $this->setRiskyAllowed(true);
        $this->setLineEnding("\n");

        $this->src = __DIR__ . '/src';
    }

    public function getRules(): array
    {
        return [
            "@PSR1" => true,
            "@PSR2" => true,
            "@PSR12" => true,
            "braces" => [
                "allow_single_line_closure" => true,
            ],
            "array_syntax" => ["syntax" => "short"],
            "cast_spaces" => true,
            "combine_consecutive_unsets" => true,
            "function_to_constant" => true,
            "native_function_invocation" => [
                'include' => [
                    '@internal',
                    'pcntl_async_signals',
                    'pcntl_signal_dispatch',
                    'pcntl_signal',
                    'posix_kill',
                    'uv_loop_new',
                    'uv_poll_start',
                    'uv_poll_stop',
                    'uv_now',
                    'uv_run',
                    'uv_poll_init_socket',
                    'uv_timer_init',
                    'uv_timer_start',
                    'uv_timer_stop',
                    'uv_signal_init',
                    'uv_signal_start',
                    'uv_signal_stop',
                    'uv_update_time',
                    'uv_is_active',
                ],
            ],
            "multiline_whitespace_before_semicolons" => true,
            "no_unused_imports" => true,
            "no_useless_else" => true,
            "no_useless_return" => true,
            "no_whitespace_before_comma_in_array" => true,
            "no_whitespace_in_blank_line" => true,
            "non_printable_character" => true,
            "normalize_index_brace" => true,
            "ordered_imports" => ['imports_order' => ['class', 'const', 'function']],
            "php_unit_construct" => true,
            "php_unit_dedicate_assert" => true,
            "php_unit_fqcn_annotation" => true,
            "phpdoc_scalar" => ["types" => ['boolean', 'double', 'integer', 'real', 'str']],
            "phpdoc_summary" => true,
            "phpdoc_types" => ["groups" => ["simple", "meta"]],
            "psr_autoloading" => ['dir' => $this->src],
            "return_type_declaration" => ["space_before" => "none"],
            "short_scalar_cast" => true,
            "single_blank_line_before_namespace" => true,
            "line_ending" => true,
        ];
    }
}

$config = new Config;
$config->getFinder()
    ->in(__DIR__ . '/examples')
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/test');

$config->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');

return $config;
