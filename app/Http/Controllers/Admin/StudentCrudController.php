<?php

namespace App\Http\Controllers\Admin;

use App\Events\UserCreated;
use App\Exceptions\UserSyncException;
use App\Http\Requests\StudentRequest;
use App\Models\Institution;
use App\Models\LeadType;
use App\Models\Period;
use App\Models\PhoneNumber;
use App\Models\Profession;
use App\Models\Student;
use App\Models\User;
use App\Traits\PeriodSelection;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\FetchOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Backpack\CRUD\app\Library\Widget;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentCrudController extends CrudController
{
    use ListOperation;
    use ShowOperation { show as traitShow; }
    use UpdateOperation;
    use CreateOperation { store as traitStore; }
    use PeriodSelection;
    use DeleteOperation { destroy as traitDelete; }
    use FetchOperation;

    public function __construct()
    {
        parent::__construct();
        $this->middleware('permission:enrollments.view', ['except' => ['dataAjax', 'show']]);
        $this->middleware('permission:student.edit', ['except' => ['index', 'show', 'search', 'dataAjax']]);
    }

    public function setup()
    {
        CRUD::setModel(Student::class);
        CRUD::setRoute(config('backpack.base.route_prefix').'/student');
        CRUD::setEntityNameStrings(__('student'), __('students'));
        CRUD::enableExportButtons();

        $permissions = backpack_user()->getAllPermissions();
        if ($permissions->contains('name', 'enrollments.edit')) {
            CRUD::addButtonFromView('line', 'selectCourse', 'selectCourse', 'beginning');
        }
    }

    public function setupListOperation()
    {

        // display lead status counts on page top
        foreach (LeadType::all() as $leadType) {
            if ($leadType->id === 4) {
                $count = Student::where('lead_type_id', $leadType->id)->orWhereNull('lead_type_id')->count();
            } else {
                $count = Student::where('lead_type_id', $leadType->id)->count();
            }
            if ($count > 0) {
                Widget::add([
                    'type' => 'view',
                    'view' => 'students.lead-type-insights-widget',
                    'studentCount' => $count,
                    'name' => Str::plural($leadType->name),
                    'icon' => $leadType->icon,
                    'leadTypeId' => $leadType->id,
                    'description' => $leadType->description,
                ])->to('before_content');
            }
        }

        // Columns.
        CRUD::setColumns([
            [
                'label' => __('ID number'),
                'type' => 'text',
                'name' => 'idnumber',
            ],
            [
                // 1-n relationship
                'label' => __('Last Name'),
                'type' => 'relationship',
                'key' => 'lastname',
                'name' => 'user',
                'attribute' => 'lastname',
                'model' => User::class,
                'orderable' => true,
                'orderLogic' => fn ($query, $column, $columnDirection) => $query->leftJoin('users', 'users.id', '=', 'students.id')
                    ->orderBy('users.lastname', $columnDirection)->select('students.*'),
                'searchLogic' => function ($query, $column, $searchTerm) {
                    $query->orWhereHas('user', function ($q) use ($searchTerm) {
                        $q->where('lastname', 'like', '%'.$searchTerm.'%');
                    });
                },
            ],

            [
                // 1-n relationship
                'label' => __('First Name'),
                'type' => 'relationship',
                'key' => 'firstname',
                'name' => 'user',
                'attribute' => 'firstname',
                'model' => User::class,
                'orderable' => true,
                'orderLogic' => fn ($query, $column, $columnDirection) => $query->leftJoin('users', 'users.id', '=', 'students.id')
                    ->orderBy('users.firstname', $columnDirection)->select('students.*'),
                'searchLogic' => function ($query, $column, $searchTerm) {
                    $query->orWhereHas('user', function ($q) use ($searchTerm) {
                        $q->where('firstname', 'like', '%'.$searchTerm.'%');
                    });
                },
            ],

            [
                // 1-n relationship
                'label' => __('Email'),
                'type' => 'relationship',
                'name' => 'user',
                'attribute' => 'email',
                'model' => User::class,
                'orderable' => true,
                'orderLogic' => fn ($query, $column, $columnDirection) => $query->leftJoin('users', 'users.id', '=', 'students.id')
                    ->orderBy('users.email', $columnDirection)->select('students.*'),
                'searchLogic' => function ($query, $column, $searchTerm) {
                    $query->orWhereHas('user', function ($q) use ($searchTerm) {
                        $q->where('email', 'like', '%'.$searchTerm.'%');
                    });
                },
            ],

            [
                'label' => __('Username'),
                'type' => 'relationship',
                'key' => 'username',
                'name' => 'user',
                'attribute' => 'username',
                'model' => User::class,
                'orderable' => false,
                'searchLogic' => false,
            ],

            [
                'label' => __('Age'),
                'name' => 'student_age',
            ],

            [
                'label' => __('Birthdate'),
                'name' => 'student_birthdate',
            ],

            [
                // n-n relationship (with pivot table)
                'label' => __('Phone number'),
                'type' => 'select_multiple',
                'name' => 'phone',
                'attribute' => 'phone_number',
                'model' => PhoneNumber::class,
            ],

            [
                // 1-n relationship
                'label' => __('Status'),
                'type' => 'text',
                'name' => 'lead_status_name',
                'orderable' => false,
            ],

        ]);

        CRUD::addFilter(
            [ // select2 filter
                'name' => 'enrolled',
                'type' => 'select2',
                'label' => __('Is Enrolled in'),
            ],
            fn () => Period::all()->pluck('name', 'id')->toArray(),
            function ($value) {
                $this->crud->query = $this->crud->query->whereHas('enrollments', fn ($query) => $query->whereHas('course', function ($q) use ($value) {
                    $q->where('period_id', $value);
                }));
            },
            function () { // if the filter is NOT active (the GET parameter "checkbox" does not exit)
            }
        );

        CRUD::addFilter([ // select2_multiple filter
            'name' => 'notenrolled',
            'type' => 'select2_multiple',
            'label' => __('Is Not Enrolled in'),
        ], fn () => Period::all()->pluck('name', 'id')->toArray(), function ($values) {
            foreach (json_decode($values, null, 512, JSON_THROW_ON_ERROR) as $value) {
                $this->crud->query = $this->crud->query->whereDoesntHave('enrollments', fn ($query) => $query->whereHas('course', function ($q) use ($value) {
                    $q->where('period_id', $value);
                }));
            }
        });

        CRUD::addFilter(
            [
                'name' => 'new_students',
                'type' => 'select2',
                'label' => __('New In'),
            ],
            fn () => Period::all()->pluck('name', 'id')->toArray(),
            function ($value) {
                CRUD::addClause('newInPeriod', $value);
            }
        );

        // select2 filter
        $this->crud->addFilter([
            'name' => 'institution_id',
            'type' => 'select2',
            'label' => __('Institution'),
        ], fn () => Institution::all()->pluck('name', 'id')->toArray(), function ($value) {
            $this->crud->addClause('where', 'institution_id', $value);
        });

        $this->crud->addFilter([
            'name' => 'lead_type_id',
            'type' => 'select2',
            'label' => __('Lead Status'),
        ], fn () => LeadType::all()->pluck('name', 'id')->toArray(), function ($value) {
            if ($value === '4') {
                $this->crud->query = $this->crud->query->where('lead_type_id', $value)->orWhere('lead_type_id', null);
            } else {
                $this->crud->addClause('where', 'lead_type_id', $value);
            }
        });
    }

    public function setupCreateOperation()
    {
        CRUD::setValidation(StudentRequest::class);
        CRUD::field('firstname')->label(__('Firstname'))->tab(__('Student Info'));
        CRUD::field('lastname')->label(__('Lastname'))->tab(__('Student Info'));
        CRUD::field('email')->label(__('Email'))->tab(__('Student Info'));
        CRUD::field('idnumber')->label(__('ID number'))->tab(__('Student Info'));
        CRUD::field('birthdate')->label(__('Birthdate'))->tab(__('Student Info'));

        $this->crud->addField([
            'name' => 'gender_id',
            'label' => __('Gender'),
            'type' => 'radio',
            'options' => [
                0 => __('Other / Rather not say'),
                1 => __('Female'),
                2 => __('Male'),
            ],
            'inline' => true,
            'tab' => __('Student Info'),
        ]);

        CRUD::addField([
            'type' => 'text',
            'name' => 'phone',
            'tab' => __('Student Info'),
            'label' => __('Phone'),
        ]);

        CRUD::addField([
            'type' => 'relationship',
            'name' => 'profession',
            'inline_create' => true,
            'tab' => __('Student Info'),
            'label' => __('Profession'),
            'attribute' => 'name',
        ]);

        CRUD::addField([
            'type' => 'relationship',
            'name' => 'institution',
            'inline_create' => true,
            'tab' => __('Student Info'),
            'label' => __('Institution'),
            'attribute' => 'name',
        ]);

        CRUD::field('address')->label(__('Address'))->tab(__('Address'));
        CRUD::field('zip_code')->label(__('zip'))->tab(__('Address'));
        CRUD::field('city')->label(__('City'))->tab(__('Address'));
        CRUD::field('state')->label(__('State'))->tab(__('Address'));
        CRUD::field('country')->label(__('Country'))->tab(__('Address'));

        CRUD::field('iban')->label('IBAN')->tab(__('Invoicing Info'));
        CRUD::field('bic')->label('BIC')->tab(__('Invoicing Info'));
    }

    public function setupUpdateOperation()
    {
        CRUD::setValidation(StudentRequest::class);
        CRUD::field('firstname')->label(__('Firstname'))->tab(__('Student Info'));
        CRUD::field('lastname')->label(__('Lastname'))->tab(__('Student Info'));
        CRUD::field('email')->label(__('Email'))->tab(__('Student Info'));
        CRUD::field('idnumber')->label(__('ID number'))->tab(__('Student Info'));
        CRUD::field('birthdate')->label(__('Birthdate'))->tab(__('Student Info'));

        $this->crud->addField([
            'name' => 'gender_id',
            'label' => __('Gender'),
            'type' => 'radio',
            'options' => [
                0 => __('Other / Rather not say'),
                1 => __('Female'),
                2 => __('Male'),
            ],
            'inline' => true,
            'tab' => __('Student Info'),
        ]);

        $this->crud->addField([
            'label' => __('Profile Picture'),
            'name' => 'image',
            'type' => 'image',
            'crop' => true,
            'tab' => __('Student Info'),
        ]);

        CRUD::addField([
            'type' => 'relationship',
            'name' => 'profession',
            'inline_create' => true,
            'tab' => __('Student Info'),
            'label' => __('Profession'),
            'attribute' => 'name',
        ]);

        CRUD::addField([
            'type' => 'relationship',
            'name' => 'institution',
            'inline_create' => [
                'entity' => 'institution',
                'force_select' => true,
                'include_main_form_fields' => ['name'],
            ],
            'tab' => __('Student Info'),
            'label' => __('Institution'),
            'attribute' => 'name',
        ]);

        CRUD::field('address')->label(__('Address'))->tab(__('Address'));
        CRUD::field('zip_code')->label(__('zip'))->tab(__('Address'));
        CRUD::field('city')->label(__('City'))->tab(__('Address'));
        CRUD::field('state')->label(__('State'))->tab(__('Address'));
        CRUD::field('country')->label(__('Country'))->tab(__('Address'));

        CRUD::field('iban')->label('IBAN')->tab(__('Invoicing Info'));
        CRUD::field('bic')->label('BIC')->tab(__('Invoicing Info'));
    }

    protected function generateUsername($fullName): string
    {
        $username_parts = array_filter(explode(' ', strtolower($fullName)));
        $username_parts = array_slice($username_parts, -2);

        $part1 = (! empty($username_parts[0])) ? substr($username_parts[0], 0, 3) : '';
        $part2 = (! empty($username_parts[1])) ? substr($username_parts[1], 0, 8) : '';
        $part3 = random_int(999, 9999);

        //str_shuffle to randomly shuffle all characters

        return $part1.$part2.$part3;
    }

    public function store(Request $request)
    {
        $request->validate([
            'firstname' => 'required|max:255',
            'lastname' => 'required|max:255',
            'email' => 'nullable|email',
        ]);

        if ($request->email && User::where('email', $request->email)->count() === 0) {
            $username = $request->email;
        } else {
            $username = $this->generateUsername($request->firstname.' '.$request->lastname);
        }

        // update the user info
        $user = User::create([
            'firstname' => $request->firstname,
            'lastname' => $request->lastname,
            'email' => $request->email ?? null,
            'username' => $username,
            'password' => Hash::make(Str::random(12)),
        ]);

        try {
            UserCreated::dispatch($user);
        } catch (Exception) {
            throw new UserSyncException();
        }

        // update the student info

        $student = Student::create([
            'id' => $user->id,
            'idnumber' => $request->idnumber,
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'country' => $request->country,
            'birthdate' => $request->birthdate,
        ]);

        // save phone number
        if ($request->phone) {
            $phone_number = PhoneNumber::firstOrCreate([
                'phone_number' => $request->phone,
                'phoneable_id' => $student->id,
                'phoneable_type' => Student::class,
            ]);

            $student->phone()->save($phone_number);
        }

        // save profession and institution
        if ($request->profession) {
            $profession = Profession::firstOrCreate([
                'name' => $request->profession,
            ]);

            $student->update([
                'profession_id' => $profession->id,
            ]);
        }

        if ($request->institution) {
            $institution = Institution::firstOrCreate([
                'name' => $request->institution,
            ]);

            $student->update([
                'institution_id' => $institution->id,
            ]);
        }

        return redirect()->route('student.index');
    }

    public function show($student)
    {
        $student = Student::findOrFail($student);

        if (! backpack_user()->isTeacher() && ! backpack_user()->can('enrollments.view')) {
            abort(403);
        }

        $comments = $student->comments;

        return view('students/show', [
            'student' => $student,
            'comments' => $comments,
            'lead_types' => LeadType::all(),
            'attendances' => $student->periodAttendance()->get(),
            'writeaccess' => backpack_user()->can('enrollments.edit') ?? 0,
        ]);
    }

    public function destroy($id)
    {
        $this->crud->hasAccessOrFail('delete');

        // get entry ID from Request (makes sure its the last ID for nested resources)
        $id = $this->crud->getCurrentEntryId() ?? $id;

        User::where('id', $id)->delete();

        return $this->crud->delete($id);
    }

    protected function fetchInstitution()
    {
        return $this->fetch([
            'model' => Institution::class,
            'searchable_attributes' => ['name'],
        ]);
    }

    protected function fetchProfession()
    {
        return $this->fetch([
            'model' => Profession::class,
            'searchable_attributes' => ['name'],
        ]);
    }
}
