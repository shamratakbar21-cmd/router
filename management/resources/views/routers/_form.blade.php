<div class="stack">
    <div class="card" style="background: #f7f7f5; border: 1px solid #ddd;">
        <h2 style="margin-top:0;">First-router onboarding checklist</h2>
        <ol class="muted" style="margin-bottom:0;">
            <li>On the appliance console: <code>nur-console-enroll status</code> and record the SSH host-key fingerprint.</li>
            <li>Enroll the WinControl public key: <code>nur-console-enroll enroll-key /path/to/wincontrol.pub</code>.</li>
            <li>Paste the matching <strong>known_hosts</strong> pin below (required for live SSH). Never accept a changed host key without console re-verification.</li>
            <li>After create: activate license → discover/observe → commit-confirm apply → create a backup.</li>
        </ol>
    </div>

    <div>
        <label for="name">Router name</label>
        <input id="name" name="name" value="{{ old('name', $routerNode->name ?? '') }}" required>
        @error('name') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="namespace_name">Namespace name</label>
        <input id="namespace_name" name="namespace_name" value="{{ old('namespace_name', $routerNode->namespace_name ?? '') }}" required>
        @error('namespace_name') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="management_interface">Management interface</label>
        <input id="management_interface" name="management_interface" value="{{ old('management_interface', $routerNode->management_interface ?? 'mgmt0') }}" required>
        @error('management_interface') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="management_cidr">Management CIDR</label>
        <input id="management_cidr" name="management_cidr" value="{{ old('management_cidr', $routerNode->management_cidr ?? '192.0.2.1/24') }}" required>
        @error('management_cidr') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="appliance_host">Appliance SSH host</label>
        <input id="appliance_host" name="appliance_host" value="{{ old('appliance_host', $routerNode->appliance_host ?? '') }}" placeholder="Management IP of the appliance">
        @error('appliance_host') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="appliance_ssh_port">Appliance SSH port</label>
        <input id="appliance_ssh_port" name="appliance_ssh_port" type="number" min="1" max="65535" value="{{ old('appliance_ssh_port', $routerNode->appliance_ssh_port ?? 22) }}">
        @error('appliance_ssh_port') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="appliance_ssh_user">Appliance SSH user</label>
        <input id="appliance_ssh_user" name="appliance_ssh_user" value="{{ old('appliance_ssh_user', $routerNode->appliance_ssh_user ?? 'nur-mgmt') }}">
        <p class="muted">Use <code>nur-mgmt</code> with forced-command enrollment on the appliance.</p>
        @error('appliance_ssh_user') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="appliance_ssh_private_key">Appliance SSH private key</label>
        <textarea id="appliance_ssh_private_key" name="appliance_ssh_private_key" placeholder="Leave blank to keep the stored private key">{{ old('appliance_ssh_private_key', '') }}</textarea>
        @if (! empty($routerNode?->appliance_ssh_private_key))
            <p class="muted">A private key is already stored for this router. Leave this blank to keep it unchanged.</p>
        @endif
        @error('appliance_ssh_private_key') <div class="error">{{ $message }}</div> @enderror
    </div>

    <div>
        <label for="appliance_ssh_known_host">Known host entry (required for live SSH)</label>
        <textarea id="appliance_ssh_known_host" name="appliance_ssh_known_host" placeholder="hostname-or-ip ssh-ed25519 AAAA…">{{ old('appliance_ssh_known_host', '') }}</textarea>
        @if (! empty($routerNode?->appliance_ssh_known_host))
            <p class="muted">A known host pin is already stored for this router. Leave this blank to keep it unchanged. Replacing it requires console fingerprint verification — changed keys are never accepted silently.</p>
        @endif
        @error('appliance_ssh_known_host') <div class="error">{{ $message }}</div> @enderror
    </div>
</div>
