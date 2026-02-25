<div x-data="{ showAdvanced: @entangle('showAdvanced') }">
    <div class="flex items-center justify-between pb-4">
        <div>
            <h3 class="font-semibold text-white">Swarm Deployment Configuration</h3>
            <p class="text-sm text-neutral-400 mt-1">Configure how this application is deployed across the Swarm cluster.</p>
        </div>
        <x-forms.button wire:click="submit">Save Configuration</x-forms.button>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Left Column: Form Sections --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Mode & Replicas --}}
            <div class="p-4 rounded-lg bg-coolgray-200">
                <h4 class="font-medium text-white text-sm pb-3">Mode & Replicas</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-forms.select id="mode" label="Deploy Mode">
                        <option value="replicated">Replicated</option>
                        <option value="global">Global (one per node)</option>
                    </x-forms.select>
                    @if ($mode === 'replicated')
                        <x-forms.input type="number" id="replicas" label="Replicas" min="0" max="100" />
                    @endif
                    <div class="flex items-end pb-1">
                        <x-forms.checkbox id="workerOnly" label="Worker Nodes Only" />
                    </div>
                </div>
            </div>

            {{-- Update Policy --}}
            <div class="p-4 rounded-lg bg-coolgray-200">
                <h4 class="font-medium text-white text-sm pb-3">Update Policy</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <x-forms.input type="number" id="updateParallelism" label="Parallelism" min="0"
                        helper="Number of tasks to update simultaneously. 0 = all at once." />
                    <x-forms.input id="updateDelay" label="Delay" placeholder="10s"
                        helper="Delay between updating batches (e.g., 10s, 1m)." />
                    <x-forms.select id="updateFailureAction" label="Failure Action">
                        <option value="rollback">Rollback</option>
                        <option value="pause">Pause</option>
                        <option value="continue">Continue</option>
                    </x-forms.select>
                    <x-forms.input id="updateMonitor" label="Monitor Period" placeholder="5s"
                        helper="Duration to monitor after update for failure." />
                    <x-forms.select id="updateOrder" label="Update Order">
                        <option value="start-first">Start First (zero-downtime)</option>
                        <option value="stop-first">Stop First</option>
                    </x-forms.select>
                    <x-forms.input type="number" id="updateMaxFailureRatio" label="Max Failure Ratio" min="0" max="1" step="0.1"
                        helper="Maximum fraction of tasks that can fail during update (0-1)." />
                </div>
            </div>

            {{-- Rollback Policy --}}
            <div class="p-4 rounded-lg bg-coolgray-200">
                <h4 class="font-medium text-white text-sm pb-3">Rollback Policy</h4>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <x-forms.input type="number" id="rollbackParallelism" label="Parallelism" min="0" />
                    <x-forms.select id="rollbackFailureAction" label="Failure Action">
                        <option value="pause">Pause</option>
                        <option value="continue">Continue</option>
                    </x-forms.select>
                    <x-forms.select id="rollbackOrder" label="Order">
                        <option value="stop-first">Stop First</option>
                        <option value="start-first">Start First</option>
                    </x-forms.select>
                </div>
            </div>

            {{-- Placement Constraints --}}
            <div class="p-4 rounded-lg bg-coolgray-200">
                <div class="flex items-center justify-between pb-3">
                    <h4 class="font-medium text-white text-sm">Placement Constraints</h4>
                    <button wire:click="addConstraint"
                        class="px-2 py-1 text-xs rounded bg-coolgray-300 text-neutral-300 hover:text-white transition-colors">
                        + Add Constraint
                    </button>
                </div>
                @if (count($constraints) === 0)
                    <p class="text-sm text-neutral-500">No placement constraints. Tasks can run on any available node.</p>
                @else
                    <div class="space-y-2">
                        @foreach ($constraints as $idx => $constraint)
                            <div class="flex items-center gap-2">
                                <x-forms.input
                                    wire:model="constraints.{{ $idx }}.field"
                                    placeholder="node.role, node.hostname, node.labels.region..."
                                    class="flex-1"
                                />
                                <select wire:model="constraints.{{ $idx }}.operator"
                                    class="px-2 py-2 text-sm rounded bg-coolgray-100 border border-coolgray-300 text-white focus:border-blue-500 focus:outline-none w-20">
                                    <option value="==">==</option>
                                    <option value="!=">!=</option>
                                </select>
                                <x-forms.input
                                    wire:model="constraints.{{ $idx }}.value"
                                    placeholder="value"
                                    class="flex-1"
                                />
                                <button wire:click="removeConstraint({{ $idx }})"
                                    class="px-2 py-2 text-red-400 hover:text-red-300 transition-colors shrink-0">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
                <p class="text-xs text-neutral-500 mt-2">
                    Common fields: <code class="text-neutral-400">node.role</code>,
                    <code class="text-neutral-400">node.hostname</code>,
                    <code class="text-neutral-400">node.labels.&lt;key&gt;</code>,
                    <code class="text-neutral-400">engine.labels.&lt;key&gt;</code>
                </p>
            </div>

            {{-- Advanced Toggle --}}
            <button @click="showAdvanced = !showAdvanced"
                class="flex items-center gap-2 text-sm text-neutral-400 hover:text-white transition-colors">
                <svg :class="{ 'rotate-90': showAdvanced }" class="w-4 h-4 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Advanced Settings
            </button>

            <div x-show="showAdvanced" x-collapse>
                <div class="space-y-6">
                    {{-- Resource Limits --}}
                    <div class="p-4 rounded-lg bg-coolgray-200">
                        <h4 class="font-medium text-white text-sm pb-3">Resource Limits</h4>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <x-forms.input type="number" id="cpuLimit" label="CPU Limit (cores)" min="0" step="0.25" placeholder="0 = unlimited"
                                helper="Maximum CPU cores this service can use. E.g., 0.5 = half a core." />
                            <x-forms.input type="number" id="memoryLimitMb" label="Memory Limit (MB)" min="0" placeholder="0 = unlimited"
                                helper="Maximum memory in megabytes." />
                            <x-forms.input type="number" id="cpuReservation" label="CPU Reservation (cores)" min="0" step="0.25" placeholder="No reservation"
                                helper="Guaranteed minimum CPU cores for scheduling." />
                            <x-forms.input type="number" id="memoryReservationMb" label="Memory Reservation (MB)" min="0" placeholder="No reservation"
                                helper="Guaranteed minimum memory for scheduling." />
                        </div>
                    </div>

                    {{-- Health Check --}}
                    <div class="p-4 rounded-lg bg-coolgray-200">
                        <h4 class="font-medium text-white text-sm pb-3">Health Check</h4>
                        <div class="space-y-3">
                            <x-forms.input id="healthCmd" label="Health Check Command" placeholder="CMD-SHELL curl -f http://localhost/ || exit 1"
                                helper="Command to determine if the container is healthy." />
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <x-forms.input id="healthInterval" label="Interval" placeholder="30s" />
                                <x-forms.input id="healthTimeout" label="Timeout" placeholder="10s" />
                                <x-forms.input type="number" id="healthRetries" label="Retries" min="0" />
                                <x-forms.input id="healthStartPeriod" label="Start Period" placeholder="40s"
                                    helper="Grace period before health checks count." />
                            </div>
                        </div>
                    </div>

                    {{-- Restart Policy --}}
                    <div class="p-4 rounded-lg bg-coolgray-200">
                        <h4 class="font-medium text-white text-sm pb-3">Restart Policy</h4>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <x-forms.select id="restartCondition" label="Condition">
                                <option value="any">Any (always restart)</option>
                                <option value="on-failure">On Failure</option>
                                <option value="none">None</option>
                            </x-forms.select>
                            <x-forms.input id="restartDelay" label="Delay" placeholder="5s"
                                helper="Wait time between restart attempts." />
                            <x-forms.input type="number" id="restartMaxAttempts" label="Max Attempts" min="0"
                                helper="Maximum restart attempts. 0 = unlimited." />
                            <x-forms.input id="restartWindow" label="Window" placeholder="120s"
                                helper="Time window to evaluate max attempts." />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right Column: YAML Preview --}}
        <div class="lg:col-span-1">
            <div class="sticky top-4">
                <div class="p-4 rounded-lg bg-coolgray-200">
                    <h4 class="font-medium text-white text-sm pb-3">Generated Deploy YAML</h4>
                    <pre class="p-3 rounded bg-black/50 border border-coolgray-300 text-xs font-mono text-green-400 overflow-x-auto max-h-[600px] overflow-y-auto whitespace-pre">{{ $yamlPreview }}</pre>
                    <p class="text-xs text-neutral-500 mt-2">
                        This YAML is generated from the form above. Save to apply changes.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
