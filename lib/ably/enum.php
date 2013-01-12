<?php

final class AuthMethod {
    const BASIC = 0;
    const TOKEN = 1;
    private function __construct() {}
}

final class PresenceState {
    const JOIN  = 0;
    const LEAVE = 1;
    private function __construct() {}
}