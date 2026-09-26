<?php

namespace App\Contracts;

/**
 * An identity's own description of a person (FR-002).
 *
 * WHY A VALUE OBJECT AND NOT THE USER MODEL
 *
 * FR-002 asks the system to retrieve or maintain the requestor's department, division and
 * reporting data. Today that comes from the user's own record, because this application is the
 * only place the data lives. With a real identity provider it comes from claims, which arrive
 * fresh on every sign-in and can differ from what was stored last time.
 *
 * Returning a plain object rather than mutating `User` keeps that difference visible at the call
 * site. A provider does not "fix" the user record; it reports what it knows, and the application
 * decides whether to trust it. A provider that silently wrote to `User` would make a claim from
 * the identity provider indistinguishable from an administrator's correction — and those two
 * need to be told apart in an audit.
 *
 * `identifier` is deliberately a plain string and not a `User`. For Entra it is the object id,
 * for a local provider the email. Either way it is the provider's key, not this application's.
 */
final readonly class UserProfile
{
    public function __construct(
        /** The provider's own key for this identity. */
        public string $identifier,
        public string $email,
        public string $name,
        public ?string $employeeNo = null,
        /** Entra's immutable object id, when the provider has one. */
        public ?string $entraObjectId = null,
        public ?int $departmentId = null,
        public ?int $divisionId = null,
        public ?int $managerId = null,
    ) {}

    /**
     * Whether anything here would change the stored record.
     *
     * Used to decide whether a sign-in is worth an audit row. A provider that reports exactly
     * what is already stored should not write a history entry on every sign-in — a trail where
     * every row says "nothing changed" is a trail nobody reads.
     *
     * @param  array{department_id: int|null, division_id: int|null, manager_id: int|null, employee_no: string|null}  $stored
     */
    public function differsFrom(array $stored): bool
    {
        return ($stored['department_id'] ?? null) !== $this->departmentId
            || ($stored['division_id'] ?? null) !== $this->divisionId
            || ($stored['manager_id'] ?? null) !== $this->managerId
            || ($stored['employee_no'] ?? null) !== $this->employeeNo;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'email' => $this->email,
            'name' => $this->name,
            'employee_no' => $this->employeeNo,
            'entra_object_id' => $this->entraObjectId,
            'department_id' => $this->departmentId,
            'division_id' => $this->divisionId,
            'manager_id' => $this->managerId,
        ];
    }
}
