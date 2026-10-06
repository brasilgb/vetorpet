<?php

namespace App\Http\Controllers\PestControl;

use App\Http\Controllers\Controller;
use App\Http\Requests\PestControl\ControlPointRequest;
use App\Models\PestControl\ControlPoint;
use App\Models\PestControl\Establishment;
use App\Models\PestControl\Lookup;
use App\Models\PestControl\Product;
use App\Services\PestControl\PestControlAuditLogger;
use App\Services\PestControl\PestControlPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ControlPointController extends Controller
{
    public function __construct(
        private readonly PestControlPermissions $permissions,
        private readonly PestControlAuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeView();

        $search = $request->get('q');
        $points = ControlPoint::with('establishment:id,name')
            ->when($search, fn ($query) => $query->where('code', 'like', '%'.$search.'%')
                ->orWhere('label', 'like', '%'.$search.'%'))
            ->orderBy('display_order')
            ->paginate(12);

        return Inertia::render('app/pest-control/points/index', ['points' => $points]);
    }

    public function create(): Response
    {
        $this->authorizeManage();

        return Inertia::render('app/pest-control/points/create-point', $this->formOptions());
    }

    public function store(ControlPointRequest $request): RedirectResponse
    {
        $this->authorizeManage();

        $point = ControlPoint::create($request->validated());
        $this->auditLogger->log($request->user()->tenant, $request->user(), 'control_point.created', $point, $request->validated());

        return redirect()->route('app.pest-control.points.index')->with('success', 'Ponto de controle cadastrado com sucesso!');
    }

    public function edit(ControlPoint $point): Response
    {
        $this->authorizeManage();

        return Inertia::render('app/pest-control/points/edit-point', [
            'point' => $point,
            ...$this->formOptions(),
        ]);
    }

    public function show(ControlPoint $point): RedirectResponse
    {
        $this->authorizeView();

        return redirect()->route('app.pest-control.points.edit', $point);
    }

    public function update(ControlPointRequest $request, ControlPoint $point): RedirectResponse
    {
        $this->authorizeManage();

        $point->update($request->validated());
        $this->auditLogger->log($request->user()->tenant, $request->user(), 'control_point.updated', $point, $request->validated());

        return redirect()->route('app.pest-control.points.edit', $point)->with('success', 'Ponto de controle atualizado com sucesso!');
    }

    public function destroy(Request $request, ControlPoint $point): RedirectResponse
    {
        $this->authorizeManage();

        if ($point->inspections()->exists()) {
            return back()->with('error', 'Não é possível excluir um ponto de controle com inspeções registradas. Desative-o para que não apareça em novas visitas.');
        }

        $this->auditLogger->log($request->user()->tenant, $request->user(), 'control_point.deleted', $point);
        $point->delete();

        return redirect()->route('app.pest-control.points.index')->with('success', 'Ponto de controle excluído com sucesso!');
    }

    private function formOptions(): array
    {
        return [
            'establishments' => Establishment::where('active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => Lookup::where('group', Lookup::GROUP_POINT_CATEGORY)->where('active', true)->orderBy('order')->get(['key', 'name']),
        ];
    }

    private function authorizeView(): void
    {
        $user = auth()->user();
        abort_unless($this->permissions->has($user, 'pest_control.points.view') || $this->permissions->has($user, 'pest_control.points.manage'), 403);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->permissions->has(auth()->user(), 'pest_control.points.manage'), 403);
    }
}
