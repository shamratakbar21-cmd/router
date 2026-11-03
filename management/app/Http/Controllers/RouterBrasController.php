<?php

namespace App\Http\Controllers;

use App\Models\RouterNode;
use App\Services\BrasSessionTable;
use App\Services\RouterConfigurationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use JsonException;

class RouterBrasController extends Controller
{
    public function edit(Request $request, RouterNode $router, BrasSessionTable $table): View
    {
        $this->authorize('update', $router);
        $observed = data_get($router->latestObservedState?->state, 'services.pppoe_server', []);
        $query = $request->query('q', '');
        $sort = $request->query('sort', 'username');
        $page = $request->query('page', 1);
        $query = is_string($query) ? substr($query, 0, 128) : '';
        $sort = is_string($sort) ? $sort : 'username';
        $page = is_scalar($page) && preg_match('/^[0-9]{1,6}$/D', (string) $page) ? (int) $page : 1;
        $sessions = $table->page($table->parse((string) data_get($observed, 'sessions_table', '')), $query, $sort, $page, $request->url());
        unset($observed['sessions_table']);

        return view('routers.bras', [
            'routerNode' => $router,
            'configuration' => data_get($router->desired_configuration, 'services.pppoe_server', ['enabled' => false]),
            'observed' => $observed,
            'sessions' => $sessions,
            'sessionQuery' => $query,
            'sessionSort' => $sort,
        ]);
    }

    public function update(Request $request, RouterNode $router, RouterConfigurationManager $manager): JsonResponse
    {
        $this->authorize('update', $router);
        if (! is_string($request->input('configuration')) || strlen($request->input('configuration')) > 524288) {
            return response()->json(['error' => 'A bounded configuration JSON object is required.'], 422);
        }
        try {
            $config = json_decode($request->input('configuration'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'Invalid configuration JSON.'], 422);
        }
        $rules = $this->validationRules();
        $validator = Validator::make(is_array($config) ? $config : [], $rules);
        if ($validator->fails()) {
            // No request flashing: embedded subscriber/RADIUS secrets must stay out of sessions.
            return response()->json(['error' => 'BRAS configuration rejected.', 'fields' => $validator->errors()->keys()], 422);
        }
        $config = $validator->validated();
        if (in_array($router->management_interface, $config['access_interfaces'] ?? [], true)) {
            return response()->json(['error' => 'Management interface cannot be an access interface.'], 422);
        }
        $secret = data_get($router->desired_secret_configuration, 'services.pppoe_server', []);
        foreach ($config['subscribers'] ?? [] as $index => $subscriber) {
            if (isset($subscriber['password'])) {
                $secret['subscribers'][$subscriber['username']] = $subscriber['password'];
            }
            unset($config['subscribers'][$index]['password']);
        }
        if (isset($config['radius']['secret'])) {
            $secret['radius_secret'] = $config['radius']['secret'];
            unset($config['radius']['secret']);
        }
        $desired = $router->desired_configuration ?? [];
        $desired['services']['pppoe_server'] = $config;
        $secrets = $router->desired_secret_configuration ?? [];
        $secrets['services']['pppoe_server'] = $secret;
        $revision = $manager->updateDesiredState($router, $desired, $secrets, $request->user());

        return response()->json(['message' => 'Experimental PPPoE server configuration staged; apply and Safe Mode confirmation are separate.', 'revision_id' => $revision->id]);
    }

    public function validationRules(): array
    {
        $token = 'regex:/^[A-Za-z0-9_@.!:+\/-]{1,128}$/D';
        $name = 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/D';

        return [
            'enabled' => ['required', 'boolean'],
            'access_interfaces' => ['required_if:enabled,true', 'array', 'max:32'],
            'access_interfaces.*' => ['string', 'distinct', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,14}$/D'],
            'auth_mode' => ['required_if:enabled,true', 'in:local,radius'],
            'gateway' => ['required_if:enabled,true', 'ipv4'],
            'max_sessions' => ['required_if:enabled,true', 'integer', 'between:1,100000'],
            'pools' => ['required_if:enabled,true', 'array', 'max:128'],
            'pools.*.name' => ['required', 'string', 'distinct', $name],
            'pools.*.start' => ['required', 'ipv4'],
            'pools.*.end' => ['required', 'ipv4'],
            'profiles' => ['sometimes', 'array', 'max:128'],
            'profiles.*.name' => ['required', 'string', 'distinct', $name],
            'profiles.*.pool' => ['required', 'string', $name],
            'profiles.*.down_kbit' => ['required', 'integer', 'between:8,10000000'],
            'profiles.*.up_kbit' => ['required', 'integer', 'between:8,10000000'],
            'subscribers' => ['sometimes', 'array', 'max:10000'],
            'subscribers.*.username' => ['required', 'string', 'distinct', $token],
            'subscribers.*.password' => ['sometimes', 'string', $token],
            'subscribers.*.profile' => ['required', 'string', $name],
            'radius' => ['sometimes', 'array:address,secret,nas_address,dae_address,interim_seconds'],
            'radius.address' => ['required_if:auth_mode,radius', 'ipv4'],
            'radius.nas_address' => ['required_if:auth_mode,radius', 'ipv4'],
            'radius.dae_address' => ['required_if:auth_mode,radius', 'ipv4', 'not_in:0.0.0.0'],
            'radius.secret' => ['sometimes', 'string', $token],
            'radius.interim_seconds' => ['required_if:auth_mode,radius', 'integer', 'between:10,3600'],
        ];
    }
}
