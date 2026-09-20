<?php

declare(strict_types=1);

namespace Lava\HttpClient\Problem;

use Lava\Core\Problem\LavaProblem;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The request cannot go on the wire as written — its method or a header name or
 * value carries something that would change what the upstream reads.
 *
 * This is the request-smuggling guard, and it is a guard rather than a
 * formality. The method becomes `CURLOPT_CUSTOMREQUEST` and a header becomes a
 * line in the header block, both written verbatim by libcurl; neither PSR-7 nor
 * libcurl checks them for `\r\n`. A method of `"GET / HTTP/1.1\r\nX: y\r\n\r\nGET"`
 * therefore puts *two* requests on the connection, the first one entirely
 * chosen by whoever supplied the method — which is an inbound visitor, in any
 * app that forwards a method or a header upstream (Lava Notes security review,
 * 2026-09-20).
 *
 * Like {@see BadRequestUrl} this is a `RequestExceptionInterface`, never
 * retried: the same request would be just as unsendable the second time.
 *
 * **The value is never printed.** A header value is where `Authorization`
 * lives, so the report names the header and what was wrong with it, and the
 * caller reads its own code to see the rest. The method is printed, escaped,
 * because a method is not a credential and seeing it is the whole diagnosis.
 */
final class UnsendableRequest extends LavaProblem implements RequestExceptionInterface
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $message,
        string $fix,
        array $context,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($message, $fix, $context);
    }

    /**
     * @param string $reason why it is not a method, from {@see \Lava\HttpClient\Url::whyBadMethod()}
     */
    public static function method(RequestInterface $request, string $method, string $reason): self
    {
        return new self(
            "The request method '" . self::readable($method) . "' cannot be sent: {$reason}.",
            'Send one of the standard methods — GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS — or a method name '
            . 'made only of the characters RFC 9110 allows. A method that came from an inbound request must be '
            . 'checked against a list the app chooses, never forwarded as it arrived.',
            ['method' => self::readable($method), 'reason' => $reason],
            $request,
        );
    }

    public static function header(RequestInterface $request, string $name): self
    {
        return new self(
            "The header '" . self::readable($name) . "' cannot be sent: its name or its value contains a line break or a null byte.",
            'Strip CR, LF and NUL from the value before setting the header. A value that came from an inbound '
            . 'request, a database row or a filename is the usual source, and a line break in one of those splits '
            . 'the request in two.',
            ['header' => self::readable($name)],
            $request,
        );
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function code(): string
    {
        return 'unsendable_request';
    }

    /**
     * A control character printed raw would do to a terminal or a log line what
     * it was about to do to the request, so the report shows it as an escape.
     */
    private static function readable(string $text): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $match): string => '\\x' . strtoupper(bin2hex($match[0])),
            $text,
        );
    }
}
