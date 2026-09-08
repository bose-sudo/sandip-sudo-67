<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Example Employee Controller demonstrating all ACL authorization patterns.
 */
class EmployeeController extends Controller
{
    /**
     * List employees.
     * Authorization via middleware on the route definition.
     * Route: Route::get('/employees', ...)->middleware('permission:employee.list');
     */
    public function index(Request $request)
    {
        // If you use route middleware, no need to check again here.
        // But you can still do conditional UI logic:
        $canDelete = $request->user()->hasPermission('employee.delete');
        $canMail   = $request->user()->hasPermission('employee.mail');

        // ... fetch employees
        return view('employees.index', compact('canDelete', 'canMail'));
    }

    /**
     * Show create form.
     * Authorization via controller method.
     */
    public function create()
    {
        $this->authorizePermission('employee.create');

        return view('employees.create');
    }

    /**
     * Store new employee.
     */
    public function store(Request $request)
    {
        $this->authorizePermission('employee.create');

        // ... validate & store
        return redirect()->route('employees.index');
    }

    /**
     * Show edit form.
     */
    public function edit(int $id)
    {
        $this->authorizePermission('employee.edit');

        // ... find employee
        return view('employees.edit', compact('id'));
    }

    /**
     * Update employee.
     */
    public function update(Request $request, int $id)
    {
        $this->authorizePermission('employee.edit');

        // ... validate & update
        return redirect()->route('employees.index');
    }

    /**
     * Delete employee.
     * Also demonstrates using hasPermission() for a JSON response.
     */
    public function destroy(Request $request, int $id)
    {
        $this->authorizePermission('employee.delete');

        // ... delete
        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('employees.index');
    }

    /**
     * Send mail to employee.
     */
    public function mail(Request $request, int $id)
    {
        $this->authorizePermission('employee.mail');

        // ... send mail
        return response()->json(['success' => true, 'message' => 'Mail sent.']);
    }
}
