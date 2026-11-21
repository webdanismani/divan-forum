<?php

namespace App\Http\Controllers\Admin;

use App\Forum\NodeTree;
use App\Forum\Permissions\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class NodeController extends Controller
{
    public function __construct(protected NodeTree $tree) {}

    public function index()
    {
        return view('admin.nodes.index', ['items' => $this->tree->flat()]);
    }

    public function create(Request $request)
    {
        $node = new Node(['type' => 'forum', 'is_active' => true, 'parent_id' => $request->integer('parent') ?: null]);

        return view('admin.nodes.form', ['node' => $node, 'parents' => $this->tree->flat()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, new Node);
        $data['display_order'] ??= (int) Node::where('parent_id', $data['parent_id'] ?? null)->max('display_order') + 10;
        $node = Node::create($data);
        $this->saveIcon($request, $node);

        return redirect()->route('admin.nodes.index')->with('success', __('The node has been created.'));
    }

    public function edit(Node $node)
    {
        $excluded = $this->tree->descendantIds($node);
        $parents = array_filter($this->tree->flat(), fn ($item) => ! in_array($item['node']->id, $excluded, true));

        return view('admin.nodes.form', compact('node', 'parents'));
    }

    public function update(Request $request, Node $node)
    {
        $node->update($this->validated($request, $node));
        $this->saveIcon($request, $node);

        return redirect()->route('admin.nodes.index')->with('success', __('The node has been updated.'));
    }

    public function destroy(Node $node)
    {
        if ($this->tree->children($node->id)->isNotEmpty()) {
            return back()->with('error', __('Move or delete the child nodes first.'));
        }

        if ($node->icon_path) {
            Storage::disk('public')->delete($node->icon_path);
        }
        $node->delete();

        return redirect()->route('admin.nodes.index')->with('success', __('The node has been deleted.'));
    }

    public function permissions(Node $node)
    {
        return view('admin.nodes.permissions', [
            'node' => $node,
            'groups' => UserGroup::orderBy('priority')->where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', 'admin'))->get(),
            'catalogue' => $this->nodeCatalogue(),
            'entries' => $node->permissionEntries->keyBy('user_group_id'),
            'moderators' => User::with('group')->whereIn('id', DB::table('node_moderators')->where('node_id', $node->id)->select('user_id'))->orderBy('username')->get(),
        ]);
    }

    public function addModerator(Request $request, Node $node)
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:30']]);
        $user = User::where('username', $data['username'])->first();

        if (! $user) {
            return back()->with('error', __('There is no member with that name.'));
        }

        DB::table('node_moderators')->insertOrIgnore(['node_id' => $node->id, 'user_id' => $user->id, 'created_at' => now()]);
        app(PermissionResolver::class)->flush();

        return back()->with('success', __(':name now moderates this forum.', ['name' => $user->username]));
    }

    public function removeModerator(Node $node, User $user)
    {
        DB::table('node_moderators')->where(['node_id' => $node->id, 'user_id' => $user->id])->delete();
        app(PermissionResolver::class)->flush();

        return back()->with('success', __('The moderator has been removed.'));
    }

    public function updatePermissions(Request $request, Node $node)
    {
        $allowed = collect($this->nodeCatalogue())->collapse()->keys()->all();
        $input = (array) $request->input('perms', []);

        foreach (UserGroup::pluck('id') as $groupId) {
            $values = collect((array) ($input[$groupId] ?? []))
                ->only($allowed)
                ->filter(fn ($value) => in_array($value, ['allow', 'never'], true))
                ->all();

            if ($values) {
                NodePermission::updateOrCreate(['node_id' => $node->id, 'user_group_id' => $groupId], ['permissions' => $values]);
            } else {
                NodePermission::where('node_id', $node->id)->where('user_group_id', $groupId)->get()->each->delete();
            }
        }

        return back()->with('success', __('The node permissions have been saved.'));
    }

    /** Only the permissions that can be overridden per node. */
    protected function nodeCatalogue(): array
    {
        return array_filter(array_map(
            fn (array $perms) => array_filter($perms, fn (array $perm) => $perm['node']),
            config('forum.permissions'),
        ));
    }

    /** Stores a newly uploaded icon (replacing the old file) or removes the icon when asked to. */
    protected function saveIcon(Request $request, Node $node): void
    {
        $request->validate([
            // No SVG: a picture that can carry script does not belong among uploads.
            'icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:512', 'dimensions:min_width=16,min_height=16,max_width=1024,max_height=1024'],
        ]);

        if (! $request->hasFile('icon') && ! $request->boolean('remove_icon')) {
            return;
        }
        if ($node->icon_path) {
            Storage::disk('public')->delete($node->icon_path);
        }
        $node->update(['icon_path' => $request->hasFile('icon') ? $request->file('icon')->store('node-icons', 'public') : null]);
    }

    protected function validated(Request $request, Node $node): array
    {
        $forbidden = $node->exists ? $this->tree->descendantIds($node) : [];

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['category', 'forum', 'link'])],
            'parent_id' => ['nullable', 'integer', Rule::exists('nodes', 'id'), Rule::notIn($forbidden)],
            'description' => ['nullable', 'string', 'max:500'],
            'link_url' => ['nullable', 'required_if:type,link', 'url:http,https', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'thread_template' => ['nullable', 'string', 'max:5000'],
            'glyph' => ['nullable', Rule::in(\App\Forum\Home::ICONS)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $data['icon'] = $data['glyph'] ?? null;
        $data['color'] = $request->boolean('color_on') ? strtolower((string) ($data['color'] ?? '')) ?: null : null;
        unset($data['glyph']);

        if ($node->exists && ! isset($data['display_order'])) {
            unset($data['display_order']);
        }
        $data['is_active'] = $request->boolean('is_active');
        $data['require_approval'] = $request->boolean('require_approval');
        $data['allow_solutions'] = $request->boolean('allow_solutions');
        $data['is_marketplace'] = $request->boolean('is_marketplace');
        $data['require_prefix'] = $request->boolean('require_prefix');
        $data['thread_template'] = filled($data['thread_template'] ?? null) ? str_replace("\r\n", "\n", trim($data['thread_template'])) : null;

        return $data;
    }
}
