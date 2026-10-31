<?php

namespace Karnoweb\TaskManager\Context;

/** Host resolvers must validate membership by identifier, including service identities. */
final readonly class ActorIdentity
{
    public function __construct(public string $id, public bool $serviceActor = false)
    {
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }
}
