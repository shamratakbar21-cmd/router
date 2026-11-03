<?php

namespace App\Http\Controllers;

use App\Exceptions\ApplianceTransportException;
use App\Models\RouterNode;
use App\Services\ApplianceSshOperation;
use App\Services\AuditLogWriter;
use App\Services\OpenWrtApplianceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RouterDiagnosticController extends Controller
{
    public function __invoke(Request $request, RouterNode $router, OpenWrtApplianceClient $client, AuditLogWriter $audit): JsonResponse
    {
        $this->authorize('update', $router);
        $data = $request->validate(['tool' => ['required', Rule::in(['ping', 'traceroute', 'nslookup'])], 'target' => ['required', 'string', 'max:253']]);
        try {
            ApplianceSshOperation::assertDiagnostic($data['tool'], $data['target']);
        } catch (ApplianceTransportException $exception) {
            throw ValidationException::withMessages(['target' => $exception->getMessage()]);
        }
        try {
            $result = $client->diagnose($router, $data['tool'], $data['target']);
            if (! isset($result['exit_code'], $result['output']) || ! is_int($result['exit_code']) || ! is_string($result['output'])) {
                throw new ApplianceTransportException(ApplianceTransportException::KIND_MALFORMED_RESPONSE, 'Invalid diagnostic response.');
            }
        } catch (ApplianceTransportException $exception) {
            $audit->write('router.diagnostic.failed', 'Router diagnostic transport failed.', $router, $request->user(), 'failed', ['tool' => $data['tool'], 'target' => $data['target'], 'kind' => $exception->kind]);

            return response()->json(['error' => ['kind' => $exception->kind, 'message' => $exception->userMessage()]], 502);
        }
        $audit->write('router.diagnostic.executed', 'Ran '.$data['tool'].' on the router.', $router, $request->user(), $result['exit_code'] === 0 ? 'success' : 'failed', ['tool' => $data['tool'], 'target' => $data['target'], 'exit_code' => $result['exit_code']]);

        return response()->json(['result' => [...$data, 'exit_code' => $result['exit_code'], 'output' => mb_convert_encoding(substr($result['output'], 0, 16000), 'UTF-8', 'UTF-8')]]);
    }
}
