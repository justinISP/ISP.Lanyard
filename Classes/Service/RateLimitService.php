<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;

/**
 * Begrenzt die Bestellungen je Client innerhalb eines Zeitfensters.
 *
 * Schlüssel ist der IP-Hash aus dem OrderProcess – die IP selbst wird
 * nie gespeichert. Je Hash liegt im Cache ISP_Lanyard_RateLimit die
 * Liste der Zeitpunkte, zu denen eine Bestellung angelegt wurde.
 *
 * Gezählt werden nur angelegte Bestellungen, keine abgewiesenen
 * Formulare.
 */
#[Flow\Scope('singleton')]
class RateLimitService
{
    /**
     * @var VariableFrontend
     */
    protected $cache;

    #[Flow\InjectConfiguration(path: 'spamProtection.rateLimit', package: 'ISP.Lanyard')]
    protected array $settings = [];

    public function isExceeded(string $clientIpHash): bool
    {
        $maxAttempts = (int)($this->settings['maxAttempts'] ?? 0);

        if ($clientIpHash === '' || $maxAttempts <= 0) {
            return false;
        }

        return count($this->recentAttempts($clientIpHash)) >= $maxAttempts;
    }

    public function registerAttempt(string $clientIpHash): void
    {
        if ($clientIpHash === '') {
            return;
        }

        $attempts = $this->recentAttempts($clientIpHash);
        $attempts[] = time();

        $this->cache->set($clientIpHash, $attempts, [], $this->interval());
    }

    /**
     * @return array<int, int> Zeitstempel innerhalb des Zeitfensters
     */
    protected function recentAttempts(string $clientIpHash): array
    {
        $attempts = $this->cache->get($clientIpHash);

        if (!is_array($attempts)) {
            return [];
        }

        $threshold = time() - $this->interval();

        return array_values(array_filter($attempts, static fn($timestamp): bool => (int)$timestamp > $threshold));
    }

    protected function interval(): int
    {
        return max(1, (int)($this->settings['interval'] ?? 3600));
    }
}
