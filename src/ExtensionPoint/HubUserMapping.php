<?php

namespace Klxm\Hub\ExtensionPoint;

use Redaxo\Core\ExtensionPoint\ExtensionPoint;

/**
 * Rechte eines Agentur-Kontos bei der Anmeldung über den Hub anpassen. subject: ['admin' => bool, 'roles' => list<int>]
 * (Rollen-IDs aus rex_user_role) oder null = Anmeldung ablehnen. Parameter: claims (geprüftes ID-Token), hub_roles.
 *
 *     Extension::register(HubUserMapping::class, static function (HubUserMapping $ep): ?array {
 *         if (in_array('technik', $ep->hubRoles, true)) {
 *             return ['admin' => false, 'roles' => [3]];
 *         }
 *         return $ep->subject;
 *     });
 *
 * @extends ExtensionPoint<array{admin: bool, roles: list<int>}|null>
 */
final class HubUserMapping extends ExtensionPoint
{
    /**
     * @param array{admin: bool, roles: list<int>}|null $mapping
     * @param array<mixed> $claims
     * @param list<string> $hubRoles
     */
    public function __construct(?array $mapping, public readonly array $claims, public readonly array $hubRoles)
    {
        parent::__construct(self::class, $mapping);
    }
}
