<?php

return [
    'agent_socket' => env(
        'NUR_AGENT_SOCKET',
        base_path('../agent/run/nur-agent.sock'),
    ),
];
