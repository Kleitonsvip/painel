<?php

namespace App\Http\Controllers\Api\V1\Vendor;
use App\Models\DeliveryMan;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class DeliveryManController extends Controller
{
    public function __construct(Request $request)
    {
        $this->middleware(function ($request, $next) {
            $restaurant=$request?->vendor?->restaurants[0];

            if(($restaurant?->restaurant_model == 'subscription' && $restaurant?->restaurant_sub?->self_delivery != 1)  || ($restaurant?->restaurant_model == 'commission' &&  $restaurant?->self_delivery_system != 1))
            {
                return response()->json([
                    'errors'=>[
                        ['code'=>'unauthorized', 'message'=>translate('messages.permission_denied')]
                    ]
                ],403);
            }
            return $next($request);
        });

    }
    public function list(Request $request)
    {
        $delivery_men = DeliveryMan::with(['rating'])
        ->withCount(['orders'=>function($query){
            $query->where('order_status','delivered');
        }])
        ->where('restaurant_id', $request?->vendor?->restaurants[0]->id)->latest()->get()->map(function($data){
            $data->identity_image = json_decode($data->identity_image);
            $data->orders_count = (double)$data->orders_count;
            $data['avg_rating'] = (double)($data?->rating[0]?->average ?? 0);
            $data['rating_count'] = (double)($data?->rating[0]?->rating_count ?? 0);
            $data['cash_in_hands'] =$data?->wallet?->collected_cash ??0;
            unset($data['rating']);
            unset($data['wallet']);
            return $data;
        });
        return response()->json($delivery_men,200);
    }

    public function search(Request $request){
        $validator = Validator::make($request->all(), [
            'search' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)],403);
        }
        $key = explode(' ', $request['search']);
        $delivery_men=DeliveryMan::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('f_name', 'like', "%{$value}%")
                    ->orWhere('l_name', 'like', "%{$value}%")
                    ->orWhere('email', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%")
                    ->orWhere('identity_number', 'like', "%{$value}%");
            }
        })->where('restaurant_id', $request?->vendor?->restaurants[0]?->id)->limit(50)->get();
        return response()->json($delivery_men);
    }

    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_man_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)],403);
        }
        $dm = DeliveryMan::with(['reviews.customer', 'rating'])
        ->withCount(['orders'=>function($query){
            $query->where('order_status','delivered');
        }])
        ->where('restaurant_id', $request?->vendor?->restaurants[0]->id)->where(['id' => $request->delivery_man_id])->first();
        $dm['avg_rating'] = (double)($dm?->rating[0]?->average ?? 0);
        $dm['rating_count'] = (double)($dm?->rating[0]?->rating_count ?? 0);
        $dm['cash_in_hands'] =$dm?->wallet?->collected_cash ?? 0;
        unset($dm['rating']);
        unset($dm['wallet']);
        return response()->json($dm, 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'identity_type' => 'required|in:passport,driving_license,nid',
            'identity_number' => 'required',
            'email' => 'required|unique:delivery_men',
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|unique:delivery_men',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'image' => 'nullable|max:2048',
            'identity_image.*' => 'nullable|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)],403);
        }

        if ($request->has('image')) {
            $image_name = Helpers::upload(dir:'delivery-man/', format:'png', image:$request->file('image'));
        } else {
            $image_name = 'def.png';
        }

        $id_img_names = [];
        if (!empty($request->file('identity_image'))) {
            foreach ($request->identity_image as $img) {
                $identity_image = Helpers::upload(dir:'delivery-man/',format: 'png',image: $img);
                array_push($id_img_names, $identity_image);
            }
            $identity_image = json_encode($id_img_names);
        } else {
            $identity_image = json_encode([]);
        }

        $dm = New DeliveryMan();
        $dm->f_name = $request->f_name;
        $dm->l_name = $request->l_name;
        $dm->email = $request->email;
        $dm->phone = $request->phone;
        $dm->identity_number = $request->identity_number;
        $dm->identity_type = $request->identity_type;
        $dm->restaurant_id =  $request?->vendor?->restaurants[0]->id;
        $dm->identity_image = $identity_image;
        $dm->image = $image_name;
        $dm->active = 0;
        $dm->earning = 0;
        $dm->type = 'restaurant_wise';
        $dm->password = bcrypt($request->password);
        $dm->save();

        return response()->json(['message' => translate('messages.deliveryman_added_successfully')], 200);
    }


    public function status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_man_id' => 'required',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)],403);
        }

        $delivery_man = DeliveryMan::find($request->delivery_man_id);
        if(!$delivery_man)
        {
            return response()->json([
                'errors'=>[
                    ['code'=>'delivery_man_id', 'message'=>translate('messages.not_found')]
                ]
            ],404);
        }
        $delivery_man->status = $request->status;

        try
        {
            if($request->status == 0)
            {   $delivery_man->auth_token = null;
                if(isset($delivery_man->fcm_token))
                {
                    $data = [
                        'title' => translate('messages.suspended'),
                        'description' => translate('messages.your_account_has_been_suspended'),
                        'order_id' => '',
                        'image' => '',
                        'type'=> 'block'
                    ];
                    Helpers::send_push_notif_to_device($delivery_man->fcm_token, $data);

                    DB::table('user_notifications')->insert([
                        'data'=> json_encode($data),
                        'delivery_man_id'=>$delivery_man->id,
                        'created_at'=>now(),
                        'updated_at'=>now()
                    ]);
                }
            }
        }
        catch (\Exception $e) {
            info($e->getMessage());
        }

        $delivery_man->save();
        return response()->json(['message' => translate('messages.deliveryman_status_updated')], 200);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'email' => 'required|unique:delivery_men,email,'.$id,
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|unique:delivery_men,phone,'.$id,
            'password' => ['nullable', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'image' => 'nullable|max:2048',
            'identity_image.*' => 'nullable|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)],403);
        }

        $delivery_man = DeliveryMan::find($id);
        if(!$delivery_man)
        {
            return response()->json([
                'errors'=>[
                    ['code'=>'delivery_man_id', 'message'=>translate('messages.not_found')]
                ]
            ],404);
        }
        if ($request->has('image')) {
            $image_name = Helpers::update(dir:'delivery-man/',old_image: $delivery_man->image, format:'png', image:$request->file('image'));
        } else {
            $image_name = $delivery_man['image'];
        }

        if ($request->has('identity_image')){
            foreach (json_decode($delivery_man['identity_image'], true) as $img) {
                if (Storage::disk('public')->exists('delivery-man/' . $img)) {
                    Storage::disk('public')->delete('delivery-man/' . $img);
                }
            }
            $img_keeper = [];
            foreach ($request->identity_image as $img) {
                $identity_image = Helpers::upload(dir:'delivery-man/',format: 'png',image: $img);
                array_push($img_keeper, $identity_image);
            }
            $identity_image = json_encode($img_keeper);
        } else {
            $identity_image = $delivery_man['identity_image'];
        }

        $delivery_man->f_name = $request->f_name;
        $delivery_man->l_name = $request->l_name;
        $delivery_man->email = $request->email;
        $delivery_man->phone = $request->phone;
        $delivery_man->identity_number = $request->identity_number;
        $delivery_man->identity_type = $request->identity_type;
        $delivery_man->identity_image = $identity_image;
        $delivery_man->image = $image_name;

        $delivery_man->password = strlen($request->password)>1?bcrypt($request->password):$delivery_man['password'];
        $delivery_man->save();

        return response()->json(['message' => translate('messages.deliveryman_updated_successfully')], 200);
    }

    public function delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_man_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)],403);
        }

        $delivery_man = DeliveryMan::find($request->delivery_man_id);
        if(!$delivery_man)
        {
            return response()->json([
                'errors'=>[
                    ['code'=>'delivery_man_id', 'message'=>translate('messages.not_found')]
                ]
            ],404);
        }
        if (Storage::disk('public')->exists('delivery-man/' . $delivery_man['image'])) {
            Storage::disk('public')->delete('delivery-man/' . $delivery_man['image']);
        }
        foreach (json_decode($delivery_man['identity_image'], true) as $img) {
            if (Storage::disk('public')->exists('delivery-man/' . $img)) {
                Storage::disk('public')->delete('delivery-man/' . $img);
            }
        }
        $delivery_man->delete();
        return response()->json(['message' => translate('messages.deliveryman_deleted_successfully')], 200);
    }
}