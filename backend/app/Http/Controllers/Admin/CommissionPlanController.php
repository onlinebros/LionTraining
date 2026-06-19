<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionPlan;
use Illuminate\Http\Request;

class CommissionPlanController extends Controller
{
    public function index()
    {
        $plans = CommissionPlan::orderBy('name')->get();
        return view('admin.commissions.plans.index', compact('plans'));
    }

    public function create()
    {
        return view('admin.commissions.plans.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            'description'          => 'nullable|string',
            'type'                 => 'required|in:flat,percentage,tiered',
            'clawback_window_days' => 'nullable|integer|min:1|max:3650',
            'is_active'            => 'boolean',
            // config fields submitted as separate inputs, assembled below
            'config_amount'        => 'nullable|numeric|min:0',
            'config_rate'          => 'nullable|numeric|min:0|max:1',
            'config_tiers'         => 'nullable|json',
        ]);

        $config = $this->buildConfig($data);

        CommissionPlan::create([
            'name'                 => $data['name'],
            'description'          => $data['description'] ?? null,
            'type'                 => $data['type'],
            'config'               => $config,
            'clawback_window_days' => $data['clawback_window_days'] ?? null,
            'is_active'            => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.commission-plans.index')
            ->with('success', 'Commission plan created.');
    }

    public function edit(CommissionPlan $commissionPlan)
    {
        return view('admin.commissions.plans.edit', compact('commissionPlan'));
    }

    public function update(Request $request, CommissionPlan $commissionPlan)
    {
        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            'description'          => 'nullable|string',
            'type'                 => 'required|in:flat,percentage,tiered',
            'clawback_window_days' => 'nullable|integer|min:1|max:3650',
            'is_active'            => 'boolean',
            'config_amount'        => 'nullable|numeric|min:0',
            'config_rate'          => 'nullable|numeric|min:0|max:1',
            'config_tiers'         => 'nullable|json',
        ]);

        $config = $this->buildConfig($data);

        $commissionPlan->update([
            'name'                 => $data['name'],
            'description'          => $data['description'] ?? null,
            'type'                 => $data['type'],
            'config'               => $config,
            'clawback_window_days' => $data['clawback_window_days'] ?? null,
            'is_active'            => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.commission-plans.index')
            ->with('success', 'Commission plan updated.');
    }

    public function destroy(CommissionPlan $commissionPlan)
    {
        if ($commissionPlan->ledgerEntries()->exists()) {
            return back()->with('error', 'Cannot delete a plan that has ledger entries.');
        }

        $commissionPlan->delete();
        return redirect()->route('admin.commission-plans.index')
            ->with('success', 'Commission plan deleted.');
    }

    private function buildConfig(array $data): array
    {
        return match ($data['type']) {
            'flat'       => ['amount' => (float) ($data['config_amount'] ?? 0)],
            'percentage' => ['rate' => (float) ($data['config_rate'] ?? 0)],
            'tiered'     => ['tiers' => json_decode($data['config_tiers'] ?? '[]', true)],
            default      => [],
        };
    }
}
