<?php
declare(strict_types=1);

namespace ISP\Lanyard\FusionObjects;

use ISP\Lanyard\Service\OrderFormService;
use ISP\Lanyard\Service\RateLimitService;
use Neos\Error\Messages\Error;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Fusion\Form\Runtime\FusionObjects\SingleStepProcessImplementation;

/**
 * Einstufiger Formularprozess für Bestellungen.
 *
 * Wie der SingleStepProcess von Neos.Fusion.Form, prüft nach dem
 * Schema aber zusätzlich die Bestellung als Ganzes. Das Schema kann
 * nur Feld für Feld validieren; Pflichtfelder je Besteller-Typ,
 * Obergrenzen und Kapazität brauchen den Blick auf alle Felder.
 *
 * Der Hash der Client-IP wird hier ermittelt, weil nur der Prozess den
 * Request kennt, und als 'clientIpHash' an die Action weitergereicht.
 * Mit ihm prüft der Prozess auch das Rate-Limit.
 */
class OrderProcessImplementation extends SingleStepProcessImplementation
{
    #[Flow\Inject]
    protected OrderFormService $orderFormService;

    #[Flow\Inject]
    protected RateLimitService $rateLimitService;

    /**
     * @param array<string, mixed> $data
     */
    public function handle(ActionRequest $request, array $data = []): void
    {
        $this->data = $data;

        $arguments = $request->getArguments();

        if (!$arguments && !$request->getInternalArguments()) {
            return;
        }

        $schema = $this->getSchema();
        $data = $schema->convert($arguments);
        $result = $schema->validate($data);

        $event = $this->orderFormService->findEvent((string)$this->fusionValue('eventIdentifier'));

        if ($event === null) {
            $result->forProperty('formToken')->addError(
                new Error('Für diese Veranstaltung sind derzeit keine Bestellungen möglich.', 1761480201)
            );
        } else {
            $result->merge($this->orderFormService->validate($event, $data, $arguments));
        }

        $request->setArgument('__submittedArguments', $data);
        $request->setArgument('__submittedArgumentValidationResults', $result);

        if ($result->hasErrors()) {
            return;
        }

        $clientIp = $this->getClientIp($request);
        $clientIpHash = $clientIp !== '' ? $this->orderFormService->hashClientIp($clientIp) : '';

        // Erst nach allen anderen Prüfungen: Wer am Rate-Limit scheitert,
        // soll nicht zusätzlich noch Feldfehler korrigieren müssen, die
        // ihm danach nichts nützen. Gezählt wird in der PlaceOrderAction,
        // wenn die Bestellung wirklich angelegt ist.
        if ($this->rateLimitService->isExceeded($clientIpHash)) {
            $result->forProperty('formToken')->addError(
                new Error('Von Ihrem Anschluss sind in kurzer Zeit bereits mehrere Anfragen eingegangen. Bitte versuchen Sie es später erneut.', 1761480202)
            );
            return;
        }

        $data['clientIpHash'] = $clientIpHash;

        $this->data = $data;
        $this->isFinished = true;
    }

    /**
     * Bevorzugt die von Flow ermittelte IP, die Trusted Proxies
     * berücksichtigt.
     */
    protected function getClientIp(ActionRequest $request): string
    {
        $httpRequest = $request->getHttpRequest();
        $clientIp = $httpRequest->getAttribute(ServerRequestAttributes::CLIENT_IP);

        if (is_string($clientIp) && $clientIp !== '') {
            return $clientIp;
        }

        return (string)($httpRequest->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
