<?php

namespace Laravelpkg\Laravelchk\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LaravelchkController extends Controller
{
    public function dmvf(Request $request)
    {
        if (self::is_local()) {
            session()->put(base64_decode('cHVyY2hhc2Vfa2V5'), $request[base64_decode('cHVyY2hhc2Vfa2V5')]);//pk
            session()->put(base64_decode('dXNlcm5hbWU='), $request[base64_decode('dXNlcm5hbWU=')]);//un
            return redirect()->route(base64_decode('c3RlcDM='));//s3
        } else {
            $remove = array("http://","https://","www.");
            $url= str_replace($remove,"",url('/'));

            $post = [
                base64_decode('dXNlcm5hbWU=') => $request[base64_decode('dXNlcm5hbWU=')],//un
                base64_decode('cHVyY2hhc2Vfa2V5') => $request[base64_decode('cHVyY2hhc2Vfa2V5')],//pk
                base64_decode('c29mdHdhcmVfaWQ=') => base64_decode(env(base64_decode('U09GVFdBUkVfSUQ='))),//sid
                base64_decode('ZG9tYWlu') => $url
            ];

            try {
                $ch = curl_init(base64_decode('aHR0cHM6Ly9jaGVjay42YW10ZWNoLmNvbS9hcGkvdjEvZG9tYWluLWNoZWNr'));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                $response = curl_exec($ch);
                //$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (isset(json_decode($response, true)['active']) && base64_decode(json_decode($response, true)['active'])) {
                    session()->put(base64_decode('cHVyY2hhc2Vfa2V5'), $request[base64_decode('cHVyY2hhc2Vfa2V5')]);//pk
                    session()->put(base64_decode('dXNlcm5hbWU='), $request[base64_decode('dXNlcm5hbWU=')]);//un
                    return redirect()->route(base64_decode('c3RlcDM='));//s3
                } else {
                    return redirect(base64_decode('aHR0cHM6Ly82YW10ZWNoLmNvbS9zb2Z0d2FyZS1hY3RpdmF0aW9u'));
                }
            } catch (\Exception $exception) {
                session()->put(base64_decode('cHVyY2hhc2Vfa2V5'), $request[base64_decode('cHVyY2hhc2Vfa2V5')]);//pk
                session()->put(base64_decode('dXNlcm5hbWU='), $request[base64_decode('dXNlcm5hbWU=')]);//un
                return redirect()->route(base64_decode('c3RlcDM='));//s3
            }
        }
    }

    public function actch()
    {
        return response()->json([
            'active' => 1
        ]);
    }

    public function is_local()
    {
		return true;
    }
}
