<?php

declare(strict_types=1);

namespace Revolt\EventLoop\Internal;

/** @internal */
final class ClosureHelper
{
    public static function getDescription(\Closure $closure): string
    {
        try {
            $reflection = new \ReflectionFunction($closure);

            $description = $reflection->name;

            if ($scopeClass = $reflection->getClosureScopeClass()) {
                $description = $scopeClass->name . '::' . $description;
            }

            if ($reflection->getFileName() !== false && $reflection->getStartLine()) {
                $description .= " defined in " . $reflection->getFileName() . ':' . $reflection->getStartLine();
            }

            return $description;
        } catch (\ReflectionException) {
            return '???';
        }
    }
}
