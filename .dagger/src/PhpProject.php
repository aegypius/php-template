<?php

declare(strict_types=1);

namespace DaggerModule;

use CompileError;
use Dagger\Attribute\DaggerFunction;
use Dagger\Attribute\DaggerObject;
use Dagger\Attribute\DefaultPath;
use Dagger\Attribute\Doc;
use Dagger\Attribute\Ignore;
use Dagger\Container;
use Dagger\Directory;
use InvalidArgumentException;

use function Dagger\dag;

#[DaggerObject]
#[Doc("PHP Code Quality functions")]
class PhpProject
{
    private static string|null $version = null;

    /**
     * Create a PHP container with the appropriate PHP version.
     *
     * This method detects the platform PHP version using Composer and creates a
     * container using the appropriate PHP image. The version is cached in a static
     * property to avoid repeated lookups.
     *
     * @param Directory $source The source directory containing composer.json
     * @param string $image The base PHP image name (default: "php")
     * @param string $variant The PHP image variant (default: "cli")
     * @return Container A container with the appropriate PHP version installed
     */
    private function php(
        Directory $source,
        string $image = "php",
        string $variant = "cli",
    ): Container {
        if (self::$version === null) {
            $output = dag()
                ->container()
                ->from("composer:2")
                ->withMountedDirectory("/app", $source)
                ->withWorkdir("/app")
                ->withExec([
                    "composer",
                    "show",
                    "--platform",
                    "php",
                ])
                ->stdout();

            foreach (explode(PHP_EOL, $output) as $line) {
                if (preg_match('/^versions\D+(?<version>(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)).*/xms', $line, $matches)) {
                    self::$version = implode('.', [
                        $matches['major'],
                        $matches['minor'],
                    ]);
                    break;
                }
            }
        }

        return dag()
            ->container()
            ->from(match (self::$version) {
                null => "{$image}:{$variant}",
                default => "{$image}:{self::$version}-{$variant}"
            })
        ;
    }

    /**
     * Allows to install vendors with
     *
     * @throws CompileError
     * @throws InvalidArgumentException
     */
    private function vendors(Directory $source): Directory
    {
        return dag()
            ->container()
            ->from("composer:2")
            ->withMountedDirectory("/app", $source)
            ->withWorkdir("/app")
            ->withExec([
                "composer",
                "install",
                "--prefer-dist",
                "--no-interaction",
            ])
            ->directory("/app/vendor");
    }

    #[DaggerFunction("check-coding-standards")]
    #[Doc("Check coding standards")]
    public function checkCodingStandards(
        #[DefaultPath("."), Ignore("**/vendor", "docs")]
        Directory $source
    ): Container {
        return $this->php(source: $source)
            ->withMountedDirectory("/app", $source)
            ->withDirectory("/app/vendor", $this->vendors($source))
            ->withWorkdir("/app")
            ->withExec([
                "./vendor/bin/ecs",
                "check",
                "--no-progress-bar",
                "--ansi",
            ]);
    }

    #[DaggerFunction("test")]
    #[Doc("Run test-suite")]
    public function test(
        #[DefaultPath("."), Ignore("**/vendor", "docs")]
        Directory $source,
        string|null $testSuite = null
    ): Container {
        $phpunit = ["./vendor/bin/phpunit"];
        if ($testSuite !== null) {
            $phpunit[] = "--testsuite={$testSuite}";
        }

        return $this->php(source: $source)
            ->withMountedDirectory("/app", $source)
            ->withDirectory("/app/vendor", $this->vendors($source))
            ->withWorkdir("/app")
            ->withExec($phpunit);
    }
}
