<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelperController;
use App\Models\ApkVersion;
use App\Models\BatteryTypes;
use App\Models\Dealer;
use App\Models\LenderProductCharges;
use App\Models\Manufacturers;
use App\Models\ProductModel;
use App\Models\ProductTypes;
use App\Models\ProductUploadExcel;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    private $helper_controller;
    public function __construct(HelperController $helper_controller)
    {
        $this->helper_controller = $helper_controller;
    }

    public function get_product_list_old(Request $request)
    {
        $userAgent = $request->header('User-Agent');
        $appVersion = $request->header('appVersion');
        $appType = $request->header('appType');

        // Check if the request is NOT coming from a web browser (common browsers usually have 'Mozilla' in their User-Agent)
        if ($userAgent && stripos($userAgent, 'Mozilla') === false) {
            if (empty($request->app_version) || empty($appVersion)) {
                return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
            }

            // Get the ApkVersion record for the file_name "finayo_b2c_apk"
            $apkRecord = ApkVersion::where('file_name', 'finayo_b2c_apk')->latest()->first();

            if (!$apkRecord) {
                // No record exists: Create a new record using the current app version
                $apkRecord = new ApkVersion;
                $apkRecord->file_name = 'finayo_b2c_apk';
                $apkRecord->file_path = ''; // adjust as needed
                $apkRecord->version = $appVersion;
                $apkRecord->save();
            } else {
                // Compare the current app version with the version in the database
                // If the current app version is older, then it is not the latest.
                // if (version_compare($appVersion, $apkRecord->version, '<')) {
                //     return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
                // } enable once you define app_type
                // // If the current app version is newer, update the record.
                // elseif (version_compare($appVersion, $apkRecord->version, '>')) {
                //     $apkRecord->version = $appVersion;
                //     $apkRecord->save();
                // } enable once you define app_type
                // If they are equal, do nothing.
            }

            $appType = $request->header('appType');
            if (isset($appType) && !empty($appType) && $appType == 'B2B') {

                $minAllowedVersion = '1.1.5';  // Lowest acceptable version
                $latestVersion = '1.1.5';      // Just for optional update logging
            } else {
                $minAllowedVersion = '1.2.4';  // Lowest acceptable version
                $latestVersion = '1.2.4';      // Just for optional update logging
            }

            if (version_compare($appVersion, $minAllowedVersion, '<')) {
                return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
            } // disable once you define app_type
            // elseif (version_compare($appVersion, $latestVersion, '>')) {
            //     // Update record if a newer version appears
            //     $apkRecord->version = $appVersion;
            //     $apkRecord->save();
            // } // disable once you define app_type

        } elseif (isset($appType) && !empty($appType)) {
            if ($appType == 'B2B') {
                $minAllowedVersion = '1.1.5';  // Lowest acceptable version
                $latestVersion = '1.1.5';      // Just for optional update logging
            } else {
                $minAllowedVersion = '1.2.4';  // Lowest acceptable version
                $latestVersion = '1.2.4';      // Just for optional update logging
            }

            if (version_compare($appVersion, $minAllowedVersion, '<')) {
                return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
            }
        }

        $product_list = [
            [
                'product_id' => "1",
                'name' => "E-Rickshaw (L3)",
                'label' => "Apply Loan",
                'icon' => asset('Assets/products/eRickshaw.png')
            ],
            [
                'product_id' => "2",
                'name' => "E-Auto (L5)",
                'label' => "Apply Loan",
                'icon' => asset('Assets/products/tuk-tuk.png')
            ],
            [
                'product_id' => "3",
                'name' => "E-Two Wheeler",
                'label' => "Apply Loan",
                'icon' => asset('Assets/products/two.png')
            ],
            [
                'product_id' => "4",
                'name' => "Battery",
                'label' => "Apply Loan",
                'icon' => asset('Assets/products/car-battery.png')
            ],
        ];

        // $product_list = json_encode($product_list);

        return response()->json([
            'status' => 'success',
            'data' => $product_list
        ], 200);
    }

    public function get_product_list(Request $request)
    {
        try {
            $deviceType = $request->header('deviceType');
            $userAgent = $request->header('User-Agent');
            $appVersion = $request->header('appVersion');
            $appType = $request->header('appType');

            $isBrowserRequest = $userAgent && stripos($userAgent, 'Mozilla') !== false;

            // Define version rules
            $versionRules = [
                'B2C' => '1.2.3',
                'default' => '1.1.5',
            ];

            // If it's not a browser request, validate version and ensure ApkVersion exists
            if (!$isBrowserRequest) {
                if (empty($appVersion)) {
                    return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
                }

                // Ensure APK version record exists
                $apkRecord = ApkVersion::where('file_name', 'finayo_b2c_apk')->latest()->first();

                if (!$apkRecord) {
                    ApkVersion::create([
                        'file_name' => 'finayo_b2c_apk',
                        'file_path' => '', // TODO: Add actual path if needed
                        'version'   => $appVersion,
                    ]);
                }

                // Determine required minimum version
                $minAllowedVersion = $versionRules[$appType] ?? $versionRules['default'];

                if (version_compare($appVersion, $minAllowedVersion, '<')) {
                    return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
                }
            }

            // For browser-based requests, optionally check appType header version if present
            if ($isBrowserRequest && !empty($appType)) {
                $minAllowedVersion = $versionRules[$appType] ?? $versionRules['default'];

                if (!empty($appVersion) && version_compare($appVersion, $minAllowedVersion, '<')) {
                    return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
                }
            }

            if ($deviceType == 'android' && !empty($appType)) {

                $minAllowedVersion = $versionRules[$appType] ?? $versionRules['default'];

                if (!empty($appVersion) && version_compare($appVersion, $minAllowedVersion, '<')) {
                    return $this->helper_controller->send_response('failed', "Please update your app to the latest version.", 422);
                }
            }

            // Product list
            $product_list = [
                [
                    'product_id' => "1",
                    'name' => "E-Rickshaw (L3)",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/eRickshaw.png')
                ],
                [
                    'product_id' => "2",
                    'name' => "E-Auto (L5)",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/tuk-tuk.png')
                ],
                [
                    'product_id' => "3",
                    'name' => "E-Two Wheeler",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/two.png')
                ],
                [
                    'product_id' => "4",
                    'name' => "Battery",
                    'label' => "Apply Loan",
                    'icon' => asset('Assets/products/car-battery.png')
                ]
                // ,
                // [
                //     'product_id' => "5",
                //     'name' => "EKA L5-3S",
                //     'label' => "Apply Loan",
                //     'icon' => ''
                // ],
                // [
                //     'product_id' => "6",
                //     'name' => "EKA L5-6S",
                //     'label' => "Apply Loan",
                //     'icon' => ''
                // ],
            ];

            return response()->json([
                'status' => 'success',
                'data' => $product_list
            ], 200);
        } catch (\Throwable $e) {
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }

    public function get_product_type_list_b2c(Request $request)
    {
        $product_list = [
            [
                'id' => 1,
                'name' => "E-Rickshaw (L3)"
            ],
            [
                'id' => 2,
                'name' => "E-Auto (L5)"
            ],
            [
                'id' => 3,
                'name' => "E-Two Wheeler"
            ],
            [
                'id' => 4,
                'name' => "Battery"
            ]
            // ,
            // [
            //     'id' => 5,
            //     'name' => "EKA L5-3S"
            // ],
            // [
            //     'id' => 6,
            //     'name' => "EKA L5-6S"
            // ],
        ];

        return $this->helper_controller->send_response('success', "Product list fetched", 200, $product_list);
    }

    public function get_sub_products(Request $request)
    {
        $input = $request->all();
        if ($input == null) {
            return response()->json([
                'status' => 'failed',
                'message' => "Request body is empty"
            ], 422);
        }

        if (!isset($input['product_id']) || empty($input['product_id'])) {
            return response()->json([
                'status' => 'failed',
                'message' => "Product id required"
            ], 422);
        }

        $product_id = $input['product_id'];

        if ($product_id == 1) {
            // 'product_id' => "1",
            // 'name' => "E-Rickshaw (L3)",
            $sub_products = [
                'EV Three Wheeler',
                'E-rickshaw'
            ];
        } elseif ($product_id == 2) {
            // 'product_id' => "2",
            // 'name' => "E-Auto (L5)",
            $sub_products = [
                'E-Auto P L5',
                'E-Auto C Cargo',
                'EKA L5-3S',
                'EKA L5-6S'
            ];
        } elseif ($product_id == 3) {
            // 'product_id' => "3",
            // 'name' => "E-Two Wheeler",
            $sub_products = [
                'EV Two Wheeler'
            ];
        } elseif ($product_id == 4) {
            // 'product_id' => "4",
            // 'name' => "Battery",
            $sub_products = [
                "Lead Acid",
                "Lithium Ion",
            ];
        } elseif ($product_id == 5) {
            $sub_products = [
                "EKA L5-3S",
            ];
        } elseif ($product_id == 6) {
            $sub_products = [
                "EKA L5-6S",
            ];
        } else {
            $sub_products = [];
        }

        if (count($sub_products) > 0) {
            return response()->json([
                'status' => 'success',
                'message' => "Sub products fetched successfully",
                'data' => $sub_products
            ], 200);
        } else {
            return response()->json([
                'status' => 'failed',
                'message' => "No products"
            ], 200);
        }
    }

    // Before
    // public function get_product_models(Request $request) {
    //     $input      = $request->all();
    //     $errorArray = [];
    //     $errorCount = 0;
    //     if($input == null) {
    //         $errorCount++;
    //         $errorArray['body'] = "Request body is empty";
    //         return $this->helper_controller->send_response("failed", $errorArray['body'], 422);
    //     }

    //     if(!isset($request->dealer_id) || empty($request->dealer_id)) {
    //         return $this->helper_controller->send_response('failed', "Dealer id is required", 422);
    //     }

    //     if(!isset($request->product_type) || empty($request->product_type)) {
    //         return $this->helper_controller->send_response('failed', "Product type is required", 422);
    //     }

    //     $dealer_id = $request->dealer_id;
    //     $product_type = $request->product_type;

    //     $dealer_details = Dealer::where('dealer_unq', $dealer_id)
    //                         ->first();
    //     if(!$dealer_details) {
    //         return $this->helper_controller->send_response('failed', "Invalid dealer id", 422);
    //     }

    //     $dealer_primary_key = $dealer_details->id;
    //     $product_list_query = ProductModel::where('status', 'active')->where('dealer_id', $dealer_primary_key)->where('product_type', $product_type);

    //     if(isset($request->sub_product_type) && !empty($request->sub_product_type)) {
    //         if($request->sub_product_type == 'Lead Acid' || $request->sub_product_type == 'Lithium Ion') {
    //             $product_list_query->where('battery_type', $request->sub_product_type);
    //         }
    //         if($request->sub_product_type == 'EKA L5-3S' || $request->sub_product_type == 'EKA L5-6S') {
    //             $product_list_query->where('sub_product_type', $request->sub_product_type);
    //         }
    //     }

    //     $product_list = $product_list_query->orderBy('id', 'asc')->get();

    //     if($product_list->count() < 1) {
    //         return $this->helper_controller->send_response('failed', "Right now we do not have any models", 422);
    //     } else {
    //         $result = [];
    //         $result['count'] = $product_list->count();
    //         foreach($product_list as $product) {
    //             // if($product_type != 'E-Rickshaw (L3)') {
    //             //     $min_loan_amount = 10000;
    //             // } else {
    //             // }
    //             $min_loan_amount = $product->min_loan_amount;

    //             $result['result'][] = [
    //                 "model_unq" => $product->model_unq,
    //                 "name" => $product->name,
    //                 "battery_type" => $product->battery_type,
    //                 "ex_showroom_price" => $product->ex_showroom_price,
    //                 "loan_to_value_ratio" => $product->loan_to_value_ratio,
    //                 "manufacture_name" => $product->manufacture_name,
    //                 "min_loan_amount" => floatval($min_loan_amount),
    //                 "max_loan_amount" => floatval($product->max_loan_amount),
    //                 "min_loan_tenure" => $product->min_loan_tenure,
    //                 "max_loan_tenure" => $product->max_loan_tenure,
    //             ];
    //         }

    //         return $this->helper_controller->send_response('success', "Models fetched successfully", 200, $result);
    //     }
    // }

    // After
    public function get_product_models(Request $request)
    {
        $input      = $request->all();
        $errorArray = [];
        $errorCount = 0;
        if ($input == null) {
            $errorCount++;
            $errorArray['body'] = "Request body is empty";
            return $this->helper_controller->send_response("failed", $errorArray['body'], 422);
        }

        if (!isset($request->dealer_id) || empty($request->dealer_id)) {
            return $this->helper_controller->send_response('failed', "Dealer id is required", 422);
        }

        if (!isset($request->product_type) || empty($request->product_type)) {
            return $this->helper_controller->send_response('failed', "Product type is required", 422);
        }

        $dealer_id = $request->dealer_id;
        $product_type = $request->product_type;

        $dealer_details = Dealer::where('dealer_unq', $dealer_id)
            ->first();
        if (!$dealer_details) {
            return $this->helper_controller->send_response('failed', "Invalid dealer id", 422);
        }

        $dealer_primary_key = $dealer_details->id;
        $product_list_query = ProductModel::where('status', 'active')->where('dealer_id', $dealer_primary_key)->where('product_type', $product_type);

        if (isset($request->sub_product_type) && !empty($request->sub_product_type)) {
            if ($request->sub_product_type == 'Lead Acid' || $request->sub_product_type == 'Lithium Ion') {
                $product_list_query->where('battery_type', $request->sub_product_type);
            }
            if ($request->sub_product_type == 'EKA L5-3S' || $request->sub_product_type == 'EKA L5-6S') {
                $product_list_query->where('sub_product_type', $request->sub_product_type);
            }
        }

        $product_list = $product_list_query->orderBy('id', 'asc')->get();

        $result = ['count' => 0, 'result' => []];

        // ---- legacy dealer products ----
        foreach ($product_list as $product) {
            $result['result'][] = [
                "model_unq"           => $product->model_unq,
                "name"                => $product->name,
                "battery_type"        => $product->battery_type,
                "ex_showroom_price"   => $product->ex_showroom_price,
                "loan_to_value_ratio" => $product->loan_to_value_ratio,
                "manufacture_name"    => $product->manufacture_name,
                "min_loan_amount"     => floatval($product->min_loan_amount),
                "max_loan_amount"     => floatval($product->max_loan_amount),
                "min_loan_tenure"     => $product->min_loan_tenure,
                "max_loan_tenure"     => $product->max_loan_tenure,
                "pricing_source"      => "legacy",
            ];
        }

        // ---- OEM dynamic products assigned to this dealer ----
        $dynamic = \App\Models\DealerProduct::with([
            'product.oem'
        ])
            ->where('dealer_id', $dealer_primary_key)
            ->where('status', 'active')
            ->get()
            ->pluck('product')
            ->filter(fn($p) => $p && $p->status === 'active' && $p->product_type === $product_type);

        // optional sub_product_type filter (battery / sub type) — mirror legacy behavior
        if (!empty($request->sub_product_type)) {
            $sub = $request->sub_product_type;
            $dynamic = $dynamic->filter(function ($p) use ($sub) {
                if (in_array($sub, ['Lead Acid', 'Lithium Ion'], true)) return $p->battery_type === $sub;
                if (in_array($sub, ['EKA L5-3S', 'EKA L5-6S'], true))   return $p->product_sub_type === $sub;
                return true;
            });
        }

        foreach ($dynamic as $p) {
            $result['result'][] = [
                "model_unq"           => $p->oem_product_unq,
                "name"                => $p->product_name,
                "battery_type"        => $p->battery_type,
                "ex_showroom_price"   => $p->ex_showroom_price,
                "loan_to_value_ratio" => $p->loan_to_value_ratio,
                "manufacture_name"    => $p->oem?->manufacturer,
                "min_loan_amount"     => floatval($p->min_loan_amount),
                "max_loan_amount"     => floatval($p->max_loan_amount),
                "min_loan_tenure"     => $p->min_loan_tenure,
                "max_loan_tenure"     => $p->max_loan_tenure,
                "pricing_source"      => "oem_dynamic",
            ];
        }

        $result['count'] = count($result['result']);

        if ($result['count'] < 1) {
            return $this->helper_controller->send_response('failed', "Right now we do not have any models", 422);
        }
        return $this->helper_controller->send_response('success', "Models fetched successfully", 200, $result);
    }

    public function get_inverter_product_models(Request $request)
    {
        $input      = $request->all();
        $errorArray = [];
        $errorCount = 0;
        if ($input == null) {
            $errorCount++;
            $errorArray['body'] = "Request body is empty";
            return $this->helper_controller->send_response("failed", $errorArray['body'], 422);
        }

        if (!isset($request->dealer_id) || empty($request->dealer_id)) {
            return $this->helper_controller->send_response('failed', "Dealer id is required", 422);
        }

        $dealer_id = $request->dealer_id;
        $product_type = $request->product_type;

        $dealer_details = Dealer::where('dealer_unq', $dealer_id)
            ->first();
        if (!$dealer_details) {
            return $this->helper_controller->send_response('failed', "Invalid dealer id", 422);
        }

        $dealer_primary_key = $dealer_details->id;
        $product_list_query = ProductModel::where('status', 'active')->where('dealer_id', $dealer_primary_key)->where('product_type', 'Inverter');

        $product_list = $product_list_query->orderBy('id', 'asc')->get();

        if ($product_list->count() < 1) {
            return $this->helper_controller->send_response('failed', "Right now we do not have any models", 422);
        } else {
            $result = [];
            $result['count'] = $product_list->count();
            foreach ($product_list as $product) {
                $min_loan_amount = $product->min_loan_amount;

                $result['result'][] = [
                    "model_unq" => $product->model_unq,
                    "name" => $product->name,
                    "battery_type" => $product->battery_type,
                    "ex_showroom_price" => $product->ex_showroom_price,
                    "loan_to_value_ratio" => $product->loan_to_value_ratio,
                    "manufacture_name" => $product->manufacture_name,
                    "min_loan_amount" => floatval($min_loan_amount),
                    "max_loan_amount" => floatval($product->max_loan_amount),
                ];
            }

            return $this->helper_controller->send_response('success', "Models fetched successfully", 200, $result);
        }
    }

    public function index(Request $request)
    {
        try {
            // Define default values
            $page = $request->get('page', 1);
            $numberOfRecords = $request->get('number_of_records', 10);
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $maxLoanAmount = $request->get('max_loan_amount');
            $minLoanTenure = $request->get('min_loan_tenure');
            $maxLoanTenure = $request->get('max_loan_tenure');
            $productType = $request->get('product_type');

            $dealerId = $request->get('dealer_id');
            $modelUnq = $request->get('model_unq');
            $name = $request->get('name');
            $batteryType = $request->get('battery_type');
            $exShowroomPrice = $request->get('ex_showroom_price');
            $loanToValueRatio = $request->get('loan_to_value_ratio');
            $manufactureName = $request->get('manufacture_name');
            $interest = $request->get('interest');

            // Validation
            $validator = Validator::make($request->all(), [
                'page' => 'integer|min:1',
                'number_of_records' => 'integer|min:1',
                'start_date' => 'date|nullable',
                'end_date' => 'date|nullable|after_or_equal:start_date',
                'max_loan_amount' => 'numeric|nullable',
                'min_loan_tenure' => 'integer|nullable',
                'max_loan_tenure' => 'integer|nullable',
                'product_type' => 'string|nullable',
                'dealer_id' => 'string|nullable',
                'model_unq' => 'string|nullable',
                'name' => 'string|nullable',
                'battery_type' => 'string|nullable',
                'ex_showroom_price' => 'numeric|nullable',
                'loan_to_value_ratio' => 'numeric|nullable',
                'manufacture_name' => 'string|nullable',
                'interest' => 'numeric|nullable',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors()
                ], 400);
            }

            // Build the query
            $query = DB::table('product_models')
                ->join('dealers', 'product_models.dealer_id', '=', 'dealers.id')
                ->where('dealers.is_deleted', false)
                ->select(
                    'dealers.dealer_unq',
                    'dealers.name as dealer_name',
                    'product_models.*',
                    // 'product_models.model_unq',
                    // 'product_models.name',
                    // 'product_models.battery_type',
                    // 'product_models.ex_showroom_price',
                    // 'product_models.loan_to_value_ratio',
                    // 'product_models.manufacture_name',
                    // 'product_models.min_loan_amount',
                    // 'product_models.max_loan_amount',
                    // 'product_models.min_loan_tenure',
                    // 'product_models.max_loan_tenure',
                    // 'product_models.product_type',
                    // 'product_models.created_at'
                );

            // Apply filters if provided
            if ($startDate && $startDate != '' && $startDate != null) {
                $startDate = Carbon::parse($startDate, 'Asia/Kolkata')->startOfDay();
                $query->where('product_models.created_at', '>=', $startDate);
            }
            if ($endDate && $endDate != '' && $endDate != null) {
                $endDate = Carbon::parse($endDate, 'Asia/Kolkata')->endOfDay();
                $query->where('product_models.created_at', '<=', $endDate);
            }
            if ($maxLoanAmount && $maxLoanAmount != '' && $maxLoanAmount != null) {
                $query->where('product_models.max_loan_amount', $maxLoanAmount);
            }
            if ($minLoanTenure && $minLoanTenure != '' && $minLoanTenure != null) {
                $query->where('product_models.min_loan_tenure', $minLoanTenure);
            }
            if ($maxLoanTenure && $maxLoanTenure != '' && $maxLoanTenure != null) {
                $query->where('product_models.max_loan_tenure', $maxLoanTenure);
            }
            if ($productType && $productType != '' && $productType != null) {
                $query->where('product_models.product_type', $productType);
            }

            // Apply additional filters
            if ($dealerId && $dealerId != '' && $dealerId != null) {
                // $query->where('product_models.dealer_id', $dealerId);
                // $query->where('dealers.dealer_unq', $dealerId);
                $query->whereRaw('LOWER(dealers.dealer_unq) LIKE ?', ['%' . strtolower($dealerId) . '%']);
            }
            if ($modelUnq && $modelUnq != '' && $modelUnq != null) {
                $query->whereRaw('LOWER(product_models.model_unq) LIKE ?', ["%" . strtolower($modelUnq) . "%"]);
            }
            if ($name && $name != '' && $name != null) {
                $query->whereRaw('LOWER(product_models.name) LIKE ?', ["%" . strtolower($name) . "%"]);
            }
            if ($batteryType && $batteryType != '' && $batteryType != null) {
                $query->whereRaw('LOWER(product_models.battery_type) LIKE ?', ["%" . strtolower($batteryType) . "%"]);
            }
            if ($exShowroomPrice && $exShowroomPrice != '' && $exShowroomPrice != null) {
                $query->where('product_models.ex_showroom_price', $exShowroomPrice);
            }
            if ($loanToValueRatio && $loanToValueRatio != '' && $loanToValueRatio != null) {
                $query->where('product_models.loan_to_value_ratio', $loanToValueRatio);
            }
            if ($manufactureName && $manufactureName != '' && $manufactureName != null) {
                $query->whereRaw('LOWER(product_models.manufacture_name) LIKE ?', ["%" . strtolower($manufactureName) . "%"]);
            }
            if ($interest && $interest != '' && $interest != null) {
                $query->where('product_models.interest', $interest);
            }

            // Paginate the results
            $products = $query->where('is_deleted', false)->orderBy('id', 'desc')->paginate($numberOfRecords, ['*'], 'page', $page);

            return response()->json($products, 200);
        } catch (Exception $e) {
            // Handle errors
            return response()->json([
                'error' => 'An error occurred while fetching product records.',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'dealer_id' => 'required|integer',
                'name' => 'required|string',
                'battery_type' => 'required|string',
                'ex_showroom_price' => 'required|numeric',
                'loan_to_value_ratio' => 'required',
                'manufacture_name' => 'required|string',
                'min_loan_amount' => 'required|numeric',
                'max_loan_amount' => 'required|numeric',
                'min_loan_tenure' => 'required|integer',
                'max_loan_tenure' => 'required|integer',
                'product_type' => 'required|string',
                'interest' => 'required|numeric',
                'processing_fee' => 'required|numeric',
                'subvention_amount' => 'required|numeric',
                'fldg' => 'required|numeric',
                'iot' => 'required|numeric',
                'insurance_1st_year' => 'required|numeric',
                'insurance_2nd_year' => 'nullable|numeric',
            ]);

            $existing_product = ProductModel::where('dealer_id', $request->input('dealer_id'))
                ->where('name', 'like', '%' . $request->input('name') . '%')
                ->where('battery_type', $request->input('battery_type'))
                ->first();

            if ($existing_product) {
                return $this->helper_controller->send_response('failed', 'Product with the same details already exists', 400, $existing_product);
            }

            $model = new ProductModel;
            $model->dealer_id = $request->input('dealer_id');
            $model->model_unq  = '';
            $model->name = $request->input('name');
            $model->battery_type = $request->input('battery_type');

            $ex_showroom_price = is_numeric($request->input('ex_showroom_price')) ? $request->input('ex_showroom_price') : 0;
            $model->ex_showroom_price = $ex_showroom_price;

            $model->loan_to_value_ratio = $request->input('loan_to_value_ratio');
            $model->manufacture_name = $request->input('manufacture_name');
            $model->min_loan_amount = $request->input('min_loan_amount');
            $model->max_loan_amount = $request->input('max_loan_amount');
            $model->min_loan_tenure = $request->input('min_loan_tenure');
            $model->max_loan_tenure = $request->input('max_loan_tenure');
            $model->product_type = $request->input('product_type');
            $model->interest = $request->input('interest');
            $model->processing_fee = $request->input('processing_fee');
            $model->subvention_amount = $request->input('subvention_amount');
            $model->fldg = $request->input('fldg');
            $model->iot = $request->input('iot');
            $model->insurance_1st_year = $request->input('insurance_1st_year');
            $model->insurance_2nd_year = $request->input('insurance_2nd_year') ?? 0;

            // Save the model
            $model->save();

            // Generate unique model_unq
            $model_unq = 'FIN-MOD-' . $model->id;
            $model->model_unq = $model_unq;
            $model->save();

            return response()->json($model, 201);
        } catch (Exception $e) {
            return response()->json(['error' => 'Unable to create model', 'message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'dealer_id' => 'required|integer',
                'name' => 'required|string',
                'battery_type' => 'nullable|string',
                'ex_showroom_price' => 'required|numeric',
                'loan_to_value_ratio' => 'required',
                'manufacture_name' => 'required|string',
                'min_loan_amount' => 'required|numeric',
                'max_loan_amount' => 'required|numeric',
                'min_loan_tenure' => 'required|integer',
                'max_loan_tenure' => 'required|integer',
                'product_type' => 'required|string',
                'interest' => 'required|numeric',
                'processing_fee' => 'required|numeric',
                'subvention_amount' => 'required|numeric',
                'fldg' => 'required|numeric',
                'iot' => 'required|numeric',
                'insurance_1st_year' => 'required|numeric',
                'insurance_2nd_year' => 'required|numeric',
                'approve_multi' => 'nullable|boolean',
            ]);

            $model = ProductModel::findOrFail($id);

            // Check if another product with the same dealer_id, name, and battery_type exists
            $existing_product = ProductModel::where('dealer_id', $request->input('dealer_id'))
                ->where('name', 'like', $request->input('name'))
                ->where('battery_type', $request->input('battery_type'))
                ->where('id', '!=', $id) // Exclude the current product
                ->first();

            if ($existing_product) {
                return $this->helper_controller->send_response('failed', 'Product with the same details already exists', 400);
            }

            $model->dealer_id = $request->input('dealer_id');
            $model->name = $request->input('name');
            $model->battery_type = $request->input('battery_type') ?? '';

            $ex_showroom_price = is_numeric($request->input('ex_showroom_price')) ? $request->input('ex_showroom_price') : 0;
            $model->ex_showroom_price = $ex_showroom_price;

            $model->loan_to_value_ratio = $request->input('loan_to_value_ratio');
            $model->manufacture_name = $request->input('manufacture_name');
            $model->min_loan_amount = $request->input('min_loan_amount');
            $model->max_loan_amount = $request->input('max_loan_amount');
            $model->min_loan_tenure = $request->input('min_loan_tenure');
            $model->max_loan_tenure = $request->input('max_loan_tenure');
            $model->product_type = $request->input('product_type');
            $model->interest = $request->input('interest');
            $model->processing_fee = $request->input('processing_fee');
            $model->subvention_amount = $request->input('subvention_amount');
            $model->fldg = $request->input('fldg');
            $model->iot = $request->input('iot');
            $model->insurance_1st_year = $request->input('insurance_1st_year');
            $model->insurance_2nd_year = $request->input('insurance_2nd_year');

            $model->approve_multi = $request->has('approve_multi') 
                ? filter_var($request->input('approve_multi'), FILTER_VALIDATE_BOOLEAN) 
                : false;

            // Save the updated model
            $model->save();

            return $this->helper_controller->send_response('success', "Model updated succesfully", 200, $model);
        } catch (Exception $e) {
            return response()->json(['error' => 'Unable to update model', 'message' => $e->getMessage()], 422);
        }
    }

    public function destroy($id)
    {
        try {
            $model = ProductModel::findOrFail($id);
            $model->delete();
            return response()->json(['message' => 'Model deleted successfully']);
        } catch (Exception $e) {
            return response()->json(['error' => 'Unable to delete model', 'message' => $e->getMessage()], 422);
        }
    }

    // Method to upload and validate Excel file
    public function upload_excel_and_validate(Request $request)
    {
        date_default_timezone_set('Asia/Kolkata');
        try {
            // Validate the incoming request
            $request->validate([
                'file' => 'required|mimes:xlsx'
            ]);

            // Store the file
            $file = $request->file('file');
            $originalFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $timestamp = date('Y_m_d_H_i_s');
            $fileName = "{$originalFileName}_{$timestamp}.{$file->getClientOriginalExtension()}";
            $path = $file->storeAs('Product/excel', $fileName, 'public');


            // Load and validate the Excel file
            $rows = Excel::toCollection(null, $file)[0];
            $validationResult = $this->validateExcelRows($rows);

            // Check if validation errors exist
            if (!empty($validationResult['errors'])) {
                // If validation errors exist, store the upload record with status 'REJECTED'
                $uploadRecord = ProductUploadExcel::create([
                    'file_name' => $fileName,
                    'status' => 'REJECTED',
                    'uploaded_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                    'errors' => "Validation errors",
                    'reason' => 'Validation errors occurred',
                    'file_path' => 'storage/' . $path
                ]);

                return $this->helper_controller->send_response('failed', 'Validation errors', 422, ['errors' => $validationResult['errors'], 'record' => $uploadRecord]);
            }

            // Create a record with status 'PENDING' if no validation errors
            $uploadRecord = ProductUploadExcel::create([
                'file_name' => $fileName,
                'status' => 'PENDING',
                'uploaded_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'errors' => null,
                'reason' => null,
                'file_path' => 'storage/' . $path
            ]);

            return $this->helper_controller->send_response('success', 'File uploaded successfully', 200, ['record' => $uploadRecord]);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
        }
    }


    private function validateExcelRows($rows)
    {
        $errors = [];
        $validRows = [];
        $headers = [
            'dealer_id',
            'name',
            'battery_type',
            'ex_showroom_price',
            'loan_to_value_ratio',
            'manufacture_name',
            'min_loan_amount',
            'max_loan_amount',
            'min_loan_tenure',
            'max_loan_tenure',
            'product_type',
            'product_sub_type',
            'interest',
            'processing_fee',
            'subvention_amount',
            'fldg',
            'iot',
            'insurance_1st_year',
            'insurance_2nd_year',
            'interest_type',
            'model_id'
        ];
        $headerCount = count($headers);

        // Skip the header row
        $rows = $rows->skip(1);

        $maxEmptyRows = 3; // Define a threshold for maximum consecutive empty rows
        $emptyRowCount = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1; // Adjust row number to account for the header row

            // Convert $row from Collection to Array
            $rowArray = $row->toArray();

            // Trim or pad the row to match header count
            $rowArray = array_slice($rowArray, 0, $headerCount);
            $rowArray = array_pad($rowArray, $headerCount, null);

            // Remove empty rows
            if (empty(array_filter($rowArray))) {
                $emptyRowCount++;
                if ($emptyRowCount > $maxEmptyRows) {
                    // Exit early if consecutive empty rows exceed the threshold
                    $errors[] = "Processing stopped after encountering {$emptyRowCount} consecutive empty rows.";
                    break; // Exit loop early
                }
                continue; // Skip to the next row
            } else {
                // Reset the empty row counter if a valid row is found
                $emptyRowCount = 0;
            }

            // Map row data to headers
            $mappedRow = array_combine($headers, $rowArray);

            if ($mappedRow === false) {
                $errors[] = "Row {$rowNumber}: Could not map row to headers.";
                continue;
            }

            // Validate based on the mapped data
            $dealer_id = $mappedRow['dealer_id'];
            $name = trim($mappedRow['name']);
            $battery_type = trim($mappedRow['battery_type']);
            $ex_showroom_price = $mappedRow['ex_showroom_price'];
            $loan_to_value_ratio = $mappedRow['loan_to_value_ratio'];
            $manufacture_name = trim($mappedRow['manufacture_name']);
            $min_loan_amount = $mappedRow['min_loan_amount'];
            $max_loan_amount = $mappedRow['max_loan_amount'];
            $min_loan_tenure = $mappedRow['min_loan_tenure'];
            $max_loan_tenure = $mappedRow['max_loan_tenure'];
            $interest_type = $mappedRow['interest_type'];

            $product_type = trim($mappedRow['product_type']);
            $product_sub_type = trim($mappedRow['product_sub_type'] ?? '');

            $model_id = trim($mappedRow['model_id'] ?? '');
            $isUpdate = $model_id !== '';

            $interest = $mappedRow['interest'];
            $processing_fee = $mappedRow['processing_fee'] ?? 0;
            $subvention_amount = $mappedRow['subvention_amount'] ?? 0;
            $fldg = $mappedRow['fldg'] ?? 0;
            $iot = $mappedRow['iot'] ?? 0;
            $insurance_1st_year = $mappedRow['insurance_1st_year'] ?? 0;
            $insurance_2nd_year = !empty($mappedRow['insurance_2nd_year']) ? $mappedRow['insurance_2nd_year'] : 0;

            // Validate model_id (update flow) against product_models.model_unq
            if ($isUpdate && !ProductModel::where('model_unq', $model_id)->exists()) {
                $errors[] = "Row {$rowNumber}: Product with model id '{$model_id}' not found";
            }

            // Validate dealer_id
            if (empty($dealer_id)) {
                $errors[] = "Row {$rowNumber}: Dealer ID is required";
            } else {
                // Validate dealer existence
                $dealer = Dealer::where('dealer_unq', $dealer_id)->first();
                if (!$dealer) {
                    $errors[] = "Row {$rowNumber}: Dealer with unique number '{$dealer_id}' not found";
                } elseif (!$isUpdate) {
                    // Duplicate check only on create
                    $existing_product = ProductModel::where('dealer_id', $dealer->id)
                        ->where('name', $name)
                        ->where('battery_type', $battery_type)
                        ->first();

                    if ($existing_product) {
                        $errors[] = "Row {$rowNumber}: Product with name '{$name}', battery type '{$battery_type}' already exists for dealer '{$dealer_id}'";
                    }
                }
            }

            // Validate required string fields
            $stringFields = [
                'name' => $name,
                'battery_type' => $battery_type,
                'manufacture_name' => $manufacture_name,
                'product_type' => $product_type,
            ];
            foreach ($stringFields as $fieldName => $value) {
                if (empty($value) || !is_string($value)) {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $fieldName)) . " is required and must be a string";
                }
            }

            if (!empty($product_type)) {
                $product_typeExist = ProductTypes::where('product_type', $product_type)->first();
                if (!$product_typeExist) {
                    $errors[] = "Row {$rowNumber}: Invalid product type";
                }
            }

            // E-Auto (L5) requires a valid product_sub_type
            if ($product_type === 'E-Auto (L5)') {
                $validSubTypes = ['E-Auto P L5', 'E-Auto C Cargo', 'EKA L5-3S', 'EKA L5-6S'];
                if (empty($product_sub_type)) {
                    $errors[] = "Row {$rowNumber}: Product sub type is required for E-Auto (L5)";
                } elseif (!in_array($product_sub_type, $validSubTypes, true)) {
                    $errors[] = "Row {$rowNumber}: Invalid product sub type for E-Auto (L5). Must be one of: " . implode(', ', $validSubTypes);
                }
            }
            if (!empty($manufacture_name)) {
                $manufacture_nameExist = Manufacturers::where('manufacturer', $manufacture_name)->first();
                if (!$manufacture_nameExist) {
                    $errors[] = "Row {$rowNumber}: Invalid manufacturer";
                }
            }
            if (!empty($battery_type)) {
                $battery_typeExist = BatteryTypes::where('battery_type', $battery_type)->first();
                if (!$battery_typeExist) {
                    $errors[] = "Row {$rowNumber}: Invalid battery type";
                }
            }

            // Validate numeric fields
            $numericFields = [
                'ex_showroom_price' => $ex_showroom_price,
                'loan_to_value_ratio' => $loan_to_value_ratio,
                'min_loan_amount' => $min_loan_amount,
                'max_loan_amount' => $max_loan_amount,
                'min_loan_tenure' => $min_loan_tenure,
                'max_loan_tenure' => $max_loan_tenure,
                'interest' => $interest,
                'processing_fee' => $processing_fee,
                'subvention_amount' => $subvention_amount ?? 0,
                'fldg' => $fldg ?? 0,
                'iot' => $iot ?? 0,
                'insurance_1st_year' => $insurance_1st_year ?? 0,
                'insurance_2nd_year' => $insurance_2nd_year ?? 0,
            ];
            // foreach ($numericFields as $fieldName => $value) {
            //     if (empty($value) || !is_numeric($value)) {
            //         $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $fieldName)) . " is required and must be a numeric value";
            //     }
            // }

            foreach ($numericFields as $fieldName => $value) {
                if ($value === null || $value === '') {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $fieldName)) . " is required and must be a numeric value";
                } elseif (!is_numeric($value)) {
                    $errors[] = "Row {$rowNumber}: " . ucfirst(str_replace('_', ' ', $fieldName)) . " must be a numeric value";
                }
            }

            // Validate LTV
            if ($loan_to_value_ratio < 1) {
                $errors[] = "Row {$rowNumber}: Loan to value ratio is required and must be a numeric value";
            }

            if (!in_array($interest_type, [ProductModel::FLAT, ProductModel::REDUCING])) {
                $errors[] = "Row {$rowNumber}: " . " interest_type must contain either flat or reducing";
            }

            // Validate insurance_2nd_year
            // if (!is_numeric($insurance_2nd_year)) {
            //     $errors[] = "Row {$rowNumber}: Insurance for the 2nd year must be a numeric value or empty";
            // }

            // Add valid row to the validRows array if no errors
            if (empty($errors)) {
                $mappedRow['sub_product_type'] = $product_sub_type;
                $mappedRow['model_id']  = $model_id;
                $mappedRow['is_update'] = $isUpdate;
                $validRows[] = $mappedRow;
            }
        }

        // If errors exist and valid rows are empty, notify of the issues
        if (!empty($errors) && empty($validRows)) {
            $errors[] = "No valid rows found.";
        }

        return [
            'errors' => $errors,
            'validRows' => $validRows
        ];
    }

    // Method to approve and process the file
    public function approve_excel_and_process(Request $request, $id)
    {
        try {
            // Validate request input
            $request->validate([
                'remarks' => 'nullable|string'
            ]);

            // Find the upload record
            $uploadRecord = ProductUploadExcel::find($id);

            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            $file_path = 'public/Product/excel/' . $uploadRecord->file_name;
            if (!Storage::exists($file_path)) {
                return $this->helper_controller->send_response('failed', 'File not found', 422);
            }

            // Load the Excel file from storage
            $rows = Excel::toCollection(null, Storage::path($file_path))[0];
            $validationResult = $this->validateExcelRows($rows);

            // Check for validation errors
            if (!empty($validationResult['errors'])) {
                $uploadRecord->update([
                    'status' => 'REJECTED',
                    'reason' => $request->input('remarks', 'Validation errors occurred'),
                    'errors' => "Validation errors",
                    'updated_by' => auth()->id()
                ]);

                return $this->helper_controller->send_response('failed', 'Validation errors', 422, $validationResult['errors']);
            }

            // Process valid rows
            // $validRows = $validationResult['validRows'];
            // foreach ($validRows as $row) {
            //     $dealer = Dealer::where('dealer_unq', $row['dealer_id'])->first();

            //     if (!$dealer) {
            //         $errors[] = "Dealer with ID '{$row['dealer_id']}' not found.";
            //         continue;
            //     }

            //     // Check for existing product
            //     $existing_product = ProductModel::where('dealer_id', $dealer->id)
            //         ->where('name', $row['name'])
            //         ->where('battery_type', $row['battery_type'])
            //         ->first();

            //     if (!$existing_product) {
            //         $interest_rate = trim($row['interest']);
            //         $productModel = new ProductModel();
            //         $productModel->dealer_id = $dealer->id;
            //         $productModel->model_unq = '';
            //         $productModel->name = trim($row['name']);
            //         $productModel->battery_type = trim($row['battery_type']);
            //         $productModel->ex_showroom_price = is_numeric($row['ex_showroom_price']) ? $row['ex_showroom_price'] : 0;
            //         $productModel->loan_to_value_ratio = $row['loan_to_value_ratio'];
            //         $productModel->manufacture_name = trim($row['manufacture_name']);
            //         $productModel->min_loan_amount = trim($row['min_loan_amount']);
            //         $productModel->max_loan_amount = trim($row['max_loan_amount']);
            //         $productModel->min_loan_tenure = trim($row['min_loan_tenure']);
            //         $productModel->max_loan_tenure = trim($row['max_loan_tenure']);
            //         $productModel->product_type = trim($row['product_type']);
            //         $productModel->interest = (float) $interest_rate;
            //         $productModel->processing_fee = $row['processing_fee'] ?? 0;
            //         $productModel->subvention_amount = $row['subvention_amount'] ?? 0;
            //         $productModel->fldg = $row['fldg'] ?? 0;
            //         $productModel->iot = $row['iot'] ?? 0;
            //         $productModel->insurance_1st_year = $row['insurance_1st_year'] ?? 0;
            //         $productModel->insurance_2nd_year = $row['insurance_2nd_year'] ?? 0;
            //         $productModel->sub_product_type = !empty($row['product_sub_type']) ? trim($row['product_sub_type']) : '';
            //         $productModel->interest_type = !empty($row['interest_type']) ? trim($row['interest_type']) : ProductModel::FLAT;

            //         $productModel->save();


            //         // Generate unique model_unq
            //         $productModel->model_unq = 'FIN-MOD-' . $productModel->id;
            //         $productModel->save();
            //     }
            // }

            // Process valid rows (bulk insert for create, DB update for update)
            $validRows = $validationResult['validRows'];
            $now = now();
            $insertData = [];

            DB::beginTransaction();

            foreach ($validRows as $row) {
                $dealer = Dealer::where('dealer_unq', $row['dealer_id'])->first();
                if (!$dealer) {
                    continue;
                }

                $payload = [
                    'dealer_id'           => $dealer->id,
                    'name'                => trim($row['name']),
                    'battery_type'        => trim($row['battery_type']),
                    'ex_showroom_price'   => is_numeric($row['ex_showroom_price']) ? $row['ex_showroom_price'] : 0,
                    'loan_to_value_ratio' => $row['loan_to_value_ratio'],
                    'manufacture_name'    => trim($row['manufacture_name']),
                    'min_loan_amount'     => trim($row['min_loan_amount']),
                    'max_loan_amount'     => trim($row['max_loan_amount']),
                    'min_loan_tenure'     => trim($row['min_loan_tenure']),
                    'max_loan_tenure'     => trim($row['max_loan_tenure']),
                    'product_type'        => trim($row['product_type']),
                    'interest'            => (float) trim($row['interest']),
                    'processing_fee'      => $row['processing_fee'] ?? 0,
                    'subvention_amount'   => $row['subvention_amount'] ?? 0,
                    'fldg'                => $row['fldg'] ?? 0,
                    'iot'                 => $row['iot'] ?? 0,
                    'insurance_1st_year'  => $row['insurance_1st_year'] ?? 0,
                    'insurance_2nd_year'  => $row['insurance_2nd_year'] ?? 0,
                    'sub_product_type'    => !empty($row['product_sub_type']) ? trim($row['product_sub_type']) : '',
                    'interest_type'       => !empty($row['interest_type']) ? trim($row['interest_type']) : ProductModel::FLAT,
                    'updated_at'          => $now,
                ];

                if (!empty($row['is_update']) && !empty($row['model_id'])) {
                    DB::table('product_models')
                        ->where('model_unq', $row['model_id'])
                        ->update($payload);
                } else {
                    $payload['model_unq']  = '';
                    $payload['created_at'] = $now;
                    $insertData[] = $payload;
                }
            }

            // Bulk insert new products, then backfill model_unq from auto-increment id
            if (!empty($insertData)) {
                DB::table('product_models')->insert($insertData);
                DB::update("UPDATE product_models SET model_unq = 'FIN-MOD-' || id WHERE model_unq = '' OR model_unq IS NULL");
            }

            DB::commit();

            // Update upload record status to APPROVED
            $uploadRecord->status = 'APPROVED';
            $uploadRecord->reason = $request->input('remarks', 'Approved');
            $uploadRecord->updated_by = auth()->id();
            $uploadRecord->save();

            // // Update upload record status to APPROVED
            // $uploadRecord->status = 'APPROVED';
            // $uploadRecord->reason = $request->input('remarks', 'Approved');
            // $uploadRecord->updated_by = auth()->id();
            // $uploadRecord->save();

            return $this->helper_controller->send_response('success', 'Products imported successfully', 200);
        } catch (Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            // Handle exceptions
            return $this->helper_controller->send_response('failed', 'Unable to process the file', 422, ['message' => $e->getMessage()]);
        }
    }


    public function get_uploaded_files(Request $request)
    {
        try {
            $query = ProductUploadExcel::query();

            // Optional date filter
            if ($request->has('start_date') && $request->has('end_date')) {
                $start_date = Carbon::parse($request->input('start_date', 'Asia/Kolkata'))->startOfDay();
                $end_date = Carbon::parse($request->input('end_date', 'Asia/Kolkata'))->endOfDay();
                $query->whereBetween('created_at', [$start_date, $end_date]);
            }

            // Pagination parameters
            $page = $request->input('page', 1);
            $records_per_page = $request->input('records_per_page', 10);

            // Join with admin_user table to fetch names
            $files = $query->leftJoin('admin_users as uploader', 'product_upload_excels.uploaded_by', '=', 'uploader.id')
                ->leftJoin('admin_users as updater', 'product_upload_excels.updated_by', '=', 'updater.id')
                ->select(
                    'product_upload_excels.*',
                    'uploader.name as uploaded_by_name',
                    'updater.name as updated_by_name'
                )
                ->orderBy('product_upload_excels.created_at', 'desc')->paginate($records_per_page, ['*'], 'page', $page);
            $files->getCollection()->transform(function ($file) {
                // Assuming file_path is the path stored in the database like 'storage/folder/subfolder'
                $file->file_path = url($file->file_path); // Or use asset($file->file_path)
                $file->approve_multi = $file->approve_multi ?? false; // new field for approve_multi, default to false if null
                return $file;
            });

            return $this->helper_controller->send_response('success', 'Files retrieved successfully', 200, $files);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to retrieve files', 422, ['message' => $e->getMessage()]);
        }
    }

    public function reject_product_excel(Request $request, $id)
    {
        try {
            // Validate the incoming request
            $request->validate([
                'remarks' => 'nullable|string|max:255'
            ]);

            // Find the file record by ID
            $uploadRecord = ProductUploadExcel::find($id);

            // Check if the file record exists
            if (!$uploadRecord) {
                return $this->helper_controller->send_response('failed', 'File record not found', 422);
            }

            // Check if the file is already rejected
            if ($uploadRecord->status === 'REJECTED') {
                return $this->helper_controller->send_response('failed', 'File already rejected', 422);
            }

            if ($uploadRecord->status === 'APPROVED') {
                return $this->helper_controller->send_response('failed', 'File already approved', 422);
            }

            // Update the file record to 'REJECTED' status
            $uploadRecord->update([
                'status' => 'REJECTED',
                'reason' => $request->input('remarks', 'Rejected'),
                'updated_by' => auth()->id()
            ]);

            // Optionally, delete the file from storage
            // $file_path = 'public/uploads/excel/' . $uploadRecord->file_name;
            // if (Storage::exists($file_path)) {
            //     Storage::delete($file_path);
            // }

            return $this->helper_controller->send_response('success', 'File rejected successfully', 200);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'Unable to reject the file', 422, ['message' => $e->getMessage()]);
        }
    }

    public function store_lender_product_charges(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'lender_id' => 'required|integer',
                'product_id' => 'required|integer',
                'charge_details' => 'required|array',
                'charge_details.*.charge_head' => 'required|string|max:255',
                'charge_details.*.charge_type' => 'nullable|string|max:100',
                'charge_details.*.charge_value' => 'nullable|integer',
                'token_id' => 'nullable|string|max:100' // Optional if updating
            ]);

            if ($validator->fails()) {
                return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
            }

            $validated = $validator->validated();
            $userId = auth()->user()->id; // Assuming you have authentication
            $ip = $this->helper_controller->get_user_ip();

            if (!empty($validated['token_id'])) {
                // Update existing records with the provided token_id to 'HISTORY'
                LenderProductCharges::where('token_id', $validated['token_id'])
                    ->update(['status' => 'HISTORY']);

                // Generate a new token in the format "dmY.primary_key"
                $tokenId = date('dmY') . (LenderProductCharges::max('id') + 1);
            } else {
                // Generate a unique token for new records
                $tokenId = date('dmY') . (LenderProductCharges::max('id') + 1);
            }

            // Create new records
            foreach ($validated['charge_details'] as $charge) {
                LenderProductCharges::create([
                    'token_id' => $tokenId,
                    'lender_id' => $validated['lender_id'],
                    'product_id' => $validated['product_id'],
                    'charge_head' => $charge['charge_head'],
                    'charge_type' => $charge['charge_type'] ?? '',
                    'charge_value' => $charge['charge_value'] ?? 0,
                    'status' => 'ACTIVE',
                    'ip' => $ip,
                    'user_id' => $userId,
                ]);
            }

            return $this->helper_controller->send_response('success', 'Records processed successfully', 201);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function getActiveChargesByProduct($product_id)
    {
        try {
            // Fetch active charges by product_id
            $charges = LenderProductCharges::where('product_id', $product_id)
                ->where('status', 'ACTIVE')
                ->with([
                    'lender:id,name',
                    'product:id,name'
                ])
                ->get();

            if ($charges->isEmpty()) {
                return $this->helper_controller->send_response('failed', 'No active charges found for this product.', 200);
            }

            return $this->helper_controller->send_response('success', "Charges fetched successfully", 200, $charges);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function getProductsByDealer($dealer_id)
    {
        try {
            // Fetch products by dealer_id
            $products = ProductModel::where('dealer_id', $dealer_id)
                ->with([
                    'dealer:id,name'
                ])
                ->get();

            if ($products->isEmpty()) {
                return $this->helper_controller->send_response('failed', 'No products found for this dealer.', 200);
            }

            return $this->helper_controller->send_response('success',  "Products fetched successfully", 200, $products);
        } catch (Exception $e) {
            return $this->helper_controller->send_response('failed', 'An error occurred: ' . $e->getMessage(), 422);
        }
    }

    public function get_dealer_product_excel_template()
    {
        try {
            $fileName = 'dealer-product-excel.xlsx';
            // Define the path to the template directory
            $filePath = public_path("Assets/templates/{$fileName}");

            // Check if the file exists and is not a directory
            if (!file_exists($filePath) || !is_file($filePath)) {

                return $this->helper_controller->send_response('failed', "File not found", 404);
            }

            // Generate a secure download link
            $downloadLink = asset("Assets/templates/{$fileName}");

            return $this->helper_controller->send_response('success', "Template url retrieved", 200, ['download_link' => $downloadLink]);
        } catch (Exception $e) {
            // Log the exception for debugging purposes

            return $this->helper_controller->send_response('failed', "An unexpected error occurred. Please try again later.", 422);
        }
    }

    public function updateMultiProductStatus(Request $request)
    {
        // Validate input using Validator::make
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'integer|exists:product_models,id',
        ]);

        if ($validator->fails()) {
            return $this->helper_controller->send_response('failed', $validator->errors()->first(), 422);
        }

        try {
            DB::beginTransaction();
            // Fetch all products
            $products = ProductModel::whereIn('id', $request->product_ids)->get();

            if ($products->isEmpty()) {
                return $this->helper_controller->send_response('failed', 'No valid products found', 422);
            }

            $updated_products = [];

            foreach ($products as $product) {
                // Toggle status
                $new_status = $product->status === 'active' ? 'inactive' : 'active';

                // Update status explicitly
                $product->status = $new_status;
                $product->modified_by = auth()->id();
                $product->ip = $this->helper_controller->get_user_ip();
                $product->save();

                // $updated_products[] = [
                //     'product_id' => $product->id,
                //     'new_status' => $new_status,
                // ];
            }

            DB::commit();
            return $this->helper_controller->send_response('success', 'Products status updated successfully', 200);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->helper_controller->send_response('failed', "An unknown error occurred: " . $e->getMessage(), 422);
        }
    }
}
