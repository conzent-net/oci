<?php

declare(strict_types=1);

namespace OCI\Admin\Console;

/**
 * The terminal side of the operator commands in bin/oci: options, output,
 * confirmation.
 *
 * The open-source edition has no screens for users, scan servers or
 * classification (they come with the Admin module), so its administrator
 * works from the command line. Options are --name=value or a bare --flag,
 * as in the other bin/oci commands. Streams are injected so the commands
 * can be tested without a terminal.
 */
final class OperatorConsole
{
    /**
     * @param list<string> $argv     bin/oci's argv: [script, command, ...options]
     * @param resource     $out
     * @param resource     $err
     * @param resource     $in
     */
    public function __construct(
        private readonly array $argv,
        private $out,
        private $err,
        private $in,
        private readonly bool $interactive,
    ) {}

    /**
     * @param list<string> $argv
     */
    public static function fromGlobals(array $argv): self
    {
        $interactive = \function_exists('stream_isatty') && @stream_isatty(STDIN);

        return new self($argv, STDOUT, STDERR, STDIN, $interactive);
    }

    public function command(): string
    {
        return (string) ($this->argv[1] ?? '');
    }

    /** The value of --name=value, or null when it is not given. */
    public function option(string $name): ?string
    {
        foreach (\array_slice($this->argv, 2) as $arg) {
            if (str_starts_with($arg, '--' . $name . '=')) {
                return trim(substr($arg, \strlen($name) + 3));
            }
        }

        return null;
    }

    /** Whether the bare flag --name was given. */
    public function flag(string $name): bool
    {
        return \in_array('--' . $name, \array_slice($this->argv, 2), true);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . "\n");
    }

    public function error(string $text): void
    {
        fwrite($this->err, 'ERROR: ' . $text . "\n");
    }

    /**
     * Ask before something that cannot simply be undone. --yes answers for
     * scripts; without a terminal and without --yes the answer is no.
     */
    public function confirm(string $question): bool
    {
        if ($this->flag('yes')) {
            return true;
        }
        if (!$this->interactive) {
            $this->error($question . ' No terminal to ask on: run it again with --yes to confirm.');

            return false;
        }

        fwrite($this->out, $question . ' Type yes to continue: ');
        $answer = fgets($this->in);

        return \is_string($answer) && strtolower(trim($answer)) === 'yes';
    }
}
