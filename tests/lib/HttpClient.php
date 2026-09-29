<?php
/**
 * A browser stand-in for the HTTP tests: keeps its own cookies, never follows
 * redirects (so tests can assert on them), and can pull the CSRF token out of
 * a rendered form.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        /** @var array<string, string> lower-cased header name => value */
        public readonly array $headers,
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** Redirect target without the scheme and host, e.g. "/login.php". */
    public function location(): string
    {
        $location = $this->header('location');
        return (string)preg_replace('#^https?://[^/]+#', '', $location);
    }
}

final class HttpClient
{
    /** @var CurlHandle */
    private $curl;

    public function __construct(private readonly string $baseUrl)
    {
        $this->curl = curl_init();
        // An empty cookie file turns on curl's in-memory cookie engine.
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, '');
    }

    public function get(string $path, array $headers = []): HttpResponse
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * @param array<string, mixed>|string $fields
     */
    public function post(string $path, array|string $fields = [], array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, $fields, $headers);
    }

    /** Fetch a page and return the first CSRF token rendered on it. */
    public function csrf(string $path): string
    {
        $body = $this->get($path)->body;
        if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m)) {
            throw new AssertionFailed('No CSRF token found on ' . $path);
        }
        return $m[1];
    }

    /** Submit a form on $path with a fresh CSRF token. */
    public function submit(string $path, array $fields, ?string $action = null): HttpResponse
    {
        $fields['csrf'] = $this->csrf($path);
        return $this->post($action ?? $path, $fields);
    }

    public function login(string $identifier, string $password): HttpResponse
    {
        return $this->submit('/login.php', ['email' => $identifier, 'password' => $password]);
    }

    private function request(string $method, string $path, array|string|null $fields, array $headers): HttpResponse
    {
        $responseHeaders = [];

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POST => $method === 'POST',
            CURLOPT_POSTFIELDS => $method === 'POST' ? (is_array($fields) ? http_build_query($fields) : (string)$fields) : null,
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($this->curl);
        if ($body === false) {
            throw new AssertionFailed('HTTP request failed: ' . curl_error($this->curl));
        }

        return new HttpResponse((int)curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), (string)$body, $responseHeaders);
    }
}
