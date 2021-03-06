<?php namespace App\Http\Controllers;

use Session;
use Utils;
use Auth;
use Log;
use Input;
use Response;
use Request;
use League\Fractal;
use League\Fractal\Manager;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\Collection;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use App\Models\EntityModel;
use App\Ninja\Serializers\ArraySerializer;
use League\Fractal\Serializer\JsonApiSerializer;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @SWG\Swagger(
 *     schemes={"http","https"},
 *     host="ninja.dev",
 *     basePath="/api/v1",
 *     @SWG\Info(
 *         version="1.0.0",
 *         title="Invoice Ninja API",
 *         description="An open-source invoicing and time-tracking app built with Laravel",
 *         termsOfService="",
 *         @SWG\Contact(
 *             email="contact@invoiceninja.com"
 *         ),
 *         @SWG\License(
 *             name="Attribution Assurance License",
 *             url="https://raw.githubusercontent.com/invoiceninja/invoiceninja/master/LICENSE"
 *         )
 *     ),
 *     @SWG\ExternalDocumentation(
 *         description="Find out more about Invoice Ninja",
 *         url="https://www.invoiceninja.com"
 *     ),
 *     @SWG\SecurityScheme(
 *         securityDefinition="api_key",
 *         type="apiKey",
 *         in="header",
 *         name="TOKEN"
 *     )
 * )
 */
class BaseAPIController extends Controller
{
    protected $manager;
    protected $serializer;

    public function __construct()
    {
        $this->manager = new Manager();

        if ($include = Request::get('include')) {
            $this->manager->parseIncludes($include);
        }

        $this->serializer = Request::get('serializer') ?: API_SERIALIZER_ARRAY;
        
        if ($this->serializer === API_SERIALIZER_JSON) {
            $this->manager->setSerializer(new JsonApiSerializer());
        } else {
            $this->manager->setSerializer(new ArraySerializer());
        }
        
        if (Utils::isNinjaDev()) {
            \DB::enableQueryLog();
        }
    }

    protected function handleAction($request)
    { 
        $entity = $request->entity();
        $action = $request->action;
        
        $repo = Utils::toCamelCase($this->entityType) . 'Repo';
        
        $this->$repo->$action($entity);
        
        return $this->itemResponse($entity);
    }

    protected function listResponse($query)
    {
        $transformerClass = EntityModel::getTransformerName($this->entityType);
        $transformer = new $transformerClass(Auth::user()->account, Input::get('serializer'));        

        $includes = $transformer->getDefaultIncludes();
        $includes = $this->getRequestIncludes($includes);

        $query->with($includes);
        
        if ($updatedAt = Input::get('updated_at')) {
            $updatedAt = date('Y-m-d H:i:s', $updatedAt);
            $query->where(function($query) use ($includes, $updatedAt) {
                $query->where('updated_at', '>=', $updatedAt);
                foreach ($includes as $include) {
                    $query->orWhereHas($include, function($query) use ($updatedAt) {
                        $query->where('updated_at', '>=', $updatedAt);
                    });
                }
            });
        }
        
        if ($clientPublicId = Input::get('client_id')) {
            $filter = function($query) use ($clientPublicId) {
                $query->where('public_id', '=', $clientPublicId);
            };
            $query->whereHas('client', $filter);
        }
        
        if ( ! Utils::hasPermission('view_all')){
            if ($this->entityType == ENTITY_USER) {
                $query->where('id', '=', Auth::user()->id);
            } else {
                $query->where('user_id', '=', Auth::user()->id);
            }
        }
        
        $data = $this->createCollection($query, $transformer, $this->entityType);

        return $this->response($data);
    }

    protected function itemResponse($item)
    {
        $transformerClass = EntityModel::getTransformerName($this->entityType);
        $transformer = new $transformerClass(Auth::user()->account, Input::get('serializer'));        

        $data = $this->createItem($item, $transformer, $this->entityType);
        
        return $this->response($data);
    }

    protected function createItem($data, $transformer, $entityType)
    {
        if ($this->serializer && $this->serializer != API_SERIALIZER_JSON) {
            $entityType = null;
        }

        $resource = new Item($data, $transformer, $entityType);
        return $this->manager->createData($resource)->toArray();
    }

    protected function createCollection($query, $transformer, $entityType)
    {
        if ($this->serializer && $this->serializer != API_SERIALIZER_JSON) {
            $entityType = null;
        }

        if (is_a($query, "Illuminate\Database\Eloquent\Builder")) {
            $limit = min(MAX_API_PAGE_SIZE, Input::get('per_page', DEFAULT_API_PAGE_SIZE));
            $resource = new Collection($query->get(), $transformer, $entityType);
            $resource->setPaginator(new IlluminatePaginatorAdapter($query->paginate($limit)));
        } else {
            $resource = new Collection($query, $transformer, $entityType);
        }
        
        return $this->manager->createData($resource)->toArray();
    }

    protected function response($response)
    {
        if (Utils::isNinjaDev()) {
            $count = count(\DB::getQueryLog());
            Log::info(Request::method() . ' - ' . Request::url() . ": $count queries");
            Log::info(json_encode(\DB::getQueryLog()));
        }
        
        $index = Request::get('index') ?: 'data';

        if ($index == 'none') {
            unset($response['meta']);
        } else {
            $meta = isset($response['meta']) ? $response['meta'] : null;
            $response = [
                $index => $response
            ];

            if ($meta) {
                $response['meta'] = $meta;
                unset($response[$index]['meta']);
            }
        }

        $response = json_encode($response, JSON_PRETTY_PRINT);
        $headers = Utils::getApiHeaders();

        return Response::make($response, 200, $headers);
    }

    protected  function errorResponse($response, $httpErrorCode = 400)
    {
        $error['error'] = $response;
        $error = json_encode($error, JSON_PRETTY_PRINT);
        $headers = Utils::getApiHeaders();

        return Response::make($error, $httpErrorCode, $headers);

    }

    protected function getRequestIncludes($data)
    {
        $included = Request::get('include');
        $included = explode(',', $included);

        foreach ($included as $include) {
            if ($include == 'invoices') {
                $data[] = 'invoices.invoice_items';
            } elseif ($include == 'client') {
                $data[] = 'client.contacts';
            } elseif ($include == 'clients') {
                $data[] = 'clients.contacts';
            } elseif ($include == 'vendors') {
                $data[] = 'vendors.vendor_contacts';
            } elseif ($include) {
                $data[] = $include;
            }
        }
        
        return $data;
    }
}
