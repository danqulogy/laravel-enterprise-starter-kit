<?php namespace App\Http\Controllers;

use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\Request;
use App\Repositories\Criteria\User\UsersWithRoles;
use App\Repositories\Criteria\User\UsersByUsernamesAscending;
use App\Repositories\Criteria\Permission\PermissionsByNamesAscending;
use App\Repositories\Criteria\Role\RolesByNamesAscending;
use App\Repositories\UserRepository as User;
use App\Repositories\PermissionRepository as Permission;
use App\Repositories\RoleRepository as Role;
use App\Repositories\AuditRepository as Audit;
use Flash;
use Auth;
use DB;

class UsersController extends Controller {

    /**
     * @var User
     */
    protected $user;

    /**
     * @var Role
     */
    protected $role;

    /**
     * @var Permission
     */
    protected $perm;

    /**
     * @var Audit
     */
    protected $audit;

    /**
     * @param User $user
     * @param Role $role
     */
    public function __construct(User $user, Role $role, Permission $perm, Audit $audit)
    {
        $this->user  = $user;
        $this->role  = $role;
        $this->perm  = $perm;
        $this->audit = $audit;
    }

    /**
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $tmp = Audit::log(Auth::user()->id, "Admin users", "Access list of users");

        $page_title = trans('admin/users/general.page.index.title'); // "Admin | Users";
        $page_description = trans('admin/users/general.page.index.description'); // "List of users";

        $users = $this->user->pushCriteria(new UsersWithRoles())->pushCriteria(new UsersByUsernamesAscending())->paginate(10);
        return view('admin.users.index', compact('users', 'page_title', 'page_description'));
    }

    /**
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $user = $this->user->find($id);

        $page_title = trans('admin/users/general.page.show.title'); // "Admin | User | Show";
        $page_description = trans('admin/users/general.page.show.description', ['full_name' => $user->full_name]); // "Displaying user";

//        $roleCollection = \App\Models\Role::take(10)->get(['id', 'display_name'])->lists('display_name', 'id');
//        $roleList = [''=>''] + $roleCollection->all();
        $perms = $this->perm->pushCriteria(new PermissionsByNamesAscending())->all();

        return view('admin.users.show', compact('user', 'perms', 'page_title', 'page_description'));
    }

    /**
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $page_title = trans('admin/users/general.page.create.title'); // "Admin | User | Create";
        $page_description = trans('admin/users/general.page.create.description'); // "Creating a new user";

        $perms = $this->perm->pushCriteria(new PermissionsByNamesAscending())->all();
        $user = new \App\User();
//        $userRoles = $user->roles;
//        $roleCollection = \App\Models\Role::take(10)->get(['id', 'display_name'])->lists('display_name', 'id');
//        $roleList = [''=>''] + $roleCollection->all();

        return view('admin.users.create', compact('user', 'perms', 'page_title', 'page_description'));
    }

    /**
     * @param CreateUserRequest $request
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function store(CreateUserRequest $request)
    {
        $attributes = $request->all();

        if ( array_key_exists('selected_roles', $attributes) ) {
            $attributes['role'] = explode(",", $attributes['selected_roles']);
        }
        // Create basic user.
        $user = $this->user->create($attributes);
        // Run the update method to set enabled status and roles membership.
        $user->update($attributes);

        Flash::success( trans('admin/users/general.status.created') ); // 'User successfully created');

        return redirect('/admin/users');
    }

    /**
     * @param $id
     *
     * @return \Illuminate\View\View
     */
    public function edit($id)
    {
        $user = $this->user->find($id);

        $page_title = trans('admin/users/general.page.edit.title'); // "Admin | User | Edit";
        $page_description = trans('admin/users/general.page.edit.description', ['full_name' => $user->full_name]); // "Editing user";

        if (!$user->isEditable())
        {
            abort(403);
        }

        $roles = $this->role->pushCriteria(new RolesByNamesAscending())->all();
        $perms = $this->perm->pushCriteria(new PermissionsByNamesAscending())->all();
//        $roleCollection = \App\Models\Role::take(10)->get(['id', 'display_name'])->lists('display_name', 'id');
//        $roleList = [''=>''] + $roleCollection->all();

        return view('admin.users.edit', compact('user', 'roles', 'perms', 'page_title', 'page_description'));
    }

    /**
     * Loads the audit log item from the id passed in, locate the relevant user, then overwrite all current attributes
     * of the user with the values from the audit log data field. Once the user saved, redirect to the edit page,
     * where the operator can inspect and further edit if needed.
     *
     * @param $id
     *
     * @return \Illuminate\View\View
     */
    public function replayEdit($id)
    {
        // Loading the audit in question.
        $audit = $this->audit->find($id);
        // Getting the attributes from the data fields.
        $att = json_decode($audit->data, true);
        // Finding the user to operate on from the id field that was populated in the
        // edit action that created this audit record.
        $user = $this->user->find($att['id']);

        $page_title = trans('admin/users/general.page.edit.title'); // "Admin | User | Edit";
        $page_description = trans('admin/users/general.page.edit.description', ['full_name' => $user->full_name]); // "Editing user";

        if (!$user->isEditable())
        {
            abort(403);
        }

        // Setting user attributes with values from audit log to replay the requested action.
        // Password is not replayed.
        $user->first_name = $att['first_name'];
        $user->last_name = $att['last_name'];
        $user->username = $att['username'];
        $user->email = $att['email'];
        $user->enabled = $att['enabled'];
        if (array_key_exists('selected_roles', $att)) {
            $aRoleIDs = explode(",", $att['selected_roles']);
            $user->roles()->sync($aRoleIDs);
        }
        if (array_key_exists('perms', $att)) {
            $user->permissions()->sync($att['perms']);
        }
        $user->save();


        $roles = $this->role->all();
        $perms = $this->perm->all();

        return view('admin.users.edit', compact('user', 'roles', 'perms', 'page_title', 'page_description'));
    }

    /**
     * @param UpdateUserRequest $request
     * @param $id
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function update(UpdateUserRequest $request, $id)
    {
        $user = $this->user->find($id);

        // Get all attribute from the request.
        $attributes = $request->all();

        // Get a copy of the attributes that we will modify to save for a replay.
        $replayAtt = $attributes;
        // Add the id of the current user for the replay action.
        $replayAtt["id"] = $id;
        // Overwrite passwords attributes as they are not replay-able.
        $replayAtt['password'] = "";
        $replayAtt['password_confirmation'] = "";
        // Create log entry with replay data.
        $tmp = Audit::log( Auth::user()->id, "Admin users", "Edits users: $user->username",
            "admin.users.replay-edit", $replayAtt );

        if (!$user->isEditable())
        {
            abort(403);
        }

        if ( array_key_exists('selected_roles', $attributes) ) {
            $attributes['role'] = explode(",", $attributes['selected_roles']);
        }

        $user->update($attributes);

        Flash::success( trans('admin/users/general.status.updated') );

        return redirect('/admin/users');
    }

    /**
     * @param $id
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function destroy($id)
    {
        $user = $this->user->find($id);

        if (!$user->isdeletable())
        {
            abort(403);
        }

        $this->user->delete($id);

        Flash::success( trans('admin/users/general.status.deleted') );

        return redirect('/admin/users');
    }

    /**
     * Delete Confirm
     *
     * @param   int   $id
     * @return  View
     */
    public function getModalDelete($id)
    {
        $error = null;

        $user = $this->user->find($id);

        if (!$user->isdeletable())
        {
            abort(403);
        }

        $modal_title = trans('admin/users/dialog.delete-confirm.title');
        $modal_cancel = trans('general.button.cancel');
        $modal_ok = trans('general.button.ok');

        if (Auth::user()->id !== $id) {
            $user = $this->user->find($id);
            $modal_route = route('admin.users.delete', array('id' => $user->id));

            $modal_body = trans('admin/users/dialog.delete-confirm.body', ['id' => $user->id, 'full_name' => $user->full_name]);
        }
        else
        {
            $error = trans('admin/users/general.error.cant-delete-yourself');
        }
        return view('modal_confirmation', compact('error', 'modal_route',
            'modal_title', 'modal_body', 'modal_cancel', 'modal_ok'));

    }

    /**
     * @param $id
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function enable($id)
    {
        $user = $this->user->find($id);
        $user->enabled = true;
        $user->save();

        Flash::success(trans('admin/users/general.status.enabled'));

        return redirect('/admin/users');
    }

    /**
     * @param $id
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function disable($id)
    {
        $user = $this->user->find($id);

        if (!$user->canBeDisabled())
        {
            Flash::error(trans('admin/users/general.error.cant-be-disabled'));
        }
        else
        {
            $user->enabled = false;
            $user->save();
            Flash::success(trans('admin/users/general.status.disabled'));
        }

        return redirect('/admin/users');
    }

    /**
     * @return \Illuminate\View\View
     */
    public function enableSelected(Request $request)
    {
        $chkUsers = $request->input('chkUser');

        if (isset($chkUsers))
        {
            foreach ($chkUsers as $user_id)
            {
                $user = $this->user->find($user_id);
                $user->enabled = true;
                $user->save();
            }
            Flash::success(trans('admin/users/general.status.global-enabled'));
        }
        else
        {
            Flash::warning(trans('admin/users/general.status.no-user-selected'));
        }
        return redirect('/admin/users');
    }

    /**
     * @return \Illuminate\View\View
     */
    public function disableSelected(Request $request)
    {
        $chkUsers = $request->input('chkUser');

        if (isset($chkUsers))
        {
            foreach ($chkUsers as $user_id)
            {
                $user = $this->user->find($user_id);
                if (!$user->canBeDisabled())
                {
                    Flash::error(trans('admin/users/general.error.cant-be-disabled'));
                }
                else
                {
                    $user->enabled = false;
                    $user->save();
                }
            }
            Flash::success(trans('admin/users/general.status.global-disabled'));
        }
        else
        {
            Flash::warning(trans('admin/users/general.status.no-user-selected'));
        }
        return redirect('/admin/users');
    }

    public function searchByName(Request $request)
    {
        $name = $request->input('query');
        $users = DB::table('users')
            ->select(DB::raw('id, first_name || " " || last_name || " (" || username || ")" as text'))
            ->where('first_name', 'like', "%$name%")
            ->orWhere('last_name', 'like', "%$name%")
            ->orWhere('username', 'like', "%$name%")
            ->get();
        return $users;
    }

    public function listByPage(Request $request)
    {
        $skipNumb = $request->input('s');
        $takeNumb = $request->input('t');

        $userCollection = \App\User::skip($skipNumb)->take($takeNumb)
            ->get(['id', 'first_name', 'last_name', 'username'])
            ->lists('full_name_and_username', 'id');
        $userList = $userCollection->all();

        return $userList;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getInfo(Request $request)
    {
        $id = $request->input('id');
        $user = $this->user->find($id);

        return $user;
    }

}