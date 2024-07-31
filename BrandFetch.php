<?php

namespace App\Admin\Actions\Page;

use OpenAdmin\Admin\Actions\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\BrandModel;
use App\Models\Category;
use App\Models\CouponModel;
use App\Models\ImportLogModel;
use Spatie\Sitemap\Sitemap;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class BrandFetch extends Action
{
    public $name = 'Fetch Brand'; // Button name
    public $icon = 'fa-cloud-download'; // Button icon

    protected $selector = '.fetch-brand';

    /**
     * @return string
     */


     public function handle(Request $request)
     {
         $selectedValue = $request->input('selected_value');
         if ($selectedValue == 6) {
             $dataArray = [
                 'new_brands' => [],
                 'existing_brands' => [],
                 'updated_brand_logos' => [],
                 'new_coupons' => [],
                 'existing_coupons' => [],
                 'new_categories' => [],
                 'existing_categories' => [],
                 'failed' => []
             ];
     
             $request->validate([
                 'file' => 'required|mimes:csv,txt'
             ]);
     
             $file = $request->file('file');
             $filePath = $file->getPathname();
             $fileHandle = fopen($filePath, 'r');
     
             // Skip the header row
             fgetcsv($fileHandle, 1000, ',');
     
             while (($data = fgetcsv($fileHandle, 1000, ',')) !== FALSE) {
                 $id = $data[0];
                 $imageName = $data[5];
                 $imageFullPath = $data[4];
                 $webUrl = $data[2];
                 $cat = $data[8];
                 $catArray = explode(',', $cat);
                 $catName = trim($catArray[0]);
                 $brandName = $data[3];
                 $couponCode = $data[6];
                 $couponDesc = $data[7];
     
                 try {
                     // Handle category creation or retrieval
                     $category = Category::firstOrCreate(['category_name' => $catName], ['active' => 1]);
                     if ($category->wasRecentlyCreated) {
                         $dataArray['new_categories'][] = [
                             'ID' => $id,
                             'Category-Data' => $category->toArray()
                         ];
                     } else {
                         $dataArray['existing_categories'][] = [
                             'ID' => $id,
                             'Category-Data' => $category->toArray()
                         ];
                     }
     
                     $category_id = $category->category_id;
     
                     $brandData = BrandModel::where('brand_name', $brandName)->first();
     
                     if ($brandData) {
                         $bLogo = $brandData->brand_logo;
                         if ($bLogo == "" || $bLogo == "images/default_logo.jpeg") {
                             $imageUrl = $imageFullPath;
                             $filename = basename($imageUrl);
                             if (!Storage::disk('admin')->exists($filename)) {
                                 $imageContent = @file_get_contents($imageUrl);
                                 if ($imageContent) {
                                     Storage::disk('admin')->put('/images/' . $filename, $imageContent);
                                     $brandData->brand_logo = '/images/' . $filename;
                                     $brandData->save();
                                     $dataArray['updated_brand_logos'][] = [
                                         'ID' => $id,
                                         'Brand-Data' => $brandData->toArray()
                                     ];
                                 }
                             }
                         }
                         $dataArray['existing_brands'][] = [
                             'ID' => $id,
                             'Brand-Data' => $brandData->toArray()
                         ];
                     } else {
                         // Create a new brand if it does not exist
                         $brand = new BrandModel();
                         $brand->brand_name = $brandName;
                         $imageUrl = $imageFullPath;
                         $filename = basename($imageUrl);
                         if (!Storage::disk('admin')->exists($filename)) {
                             $imageContent = @file_get_contents($imageUrl);
                             if ($imageContent) {
                                 Storage::disk('admin')->put('/images/' . $filename, $imageContent);
                                 $brand->brand_logo = '/images/' . $filename;
                             }
                         }
                         $brand->brand_website = $webUrl ?? '';
                         $brand->slug = strtolower($brandName);
                         $brand->category_id = $category_id;
                         $brand->active = 1;
                         $brand->save();
     
                         $dataArray['new_brands'][] = [
                             'ID' => $id,
                             'Brand-Data' => $brand->toArray()
                         ];
                     }
     
                     // Check if the coupon exists
                     $couponData = CouponModel::where('coupon_code', $couponCode)->first();
                     if ($couponData) {
                         $dataArray['existing_coupons'][] = [
                             'ID' => $id,
                             'Coupon-Data' => $couponData->toArray()
                         ];
                     } else {
                         $coupon = new CouponModel();
                         $coupon->coupon_code = $couponCode ?? 'null';
                         $coupon->coupon_desc = $couponDesc;
     
                         $brand = BrandModel::where('brand_name', $brandName)->first();
                         $brandId = $brand->brand_id ?? null;
                         if ($brandId) {
                             $coupon->brand_id = $brandId;
                         }
     
                         $coupon->affiliate_link = $webUrl ?? '';
                         $coupon->keywords = $brandName ?? '';
                         $coupon->tags = '';
     
                         $category = Category::where('category_name', $catName)->first();
                         $categoryId = $category->category_id ?? null;
                         if ($categoryId) {
                             $coupon->category_id = $categoryId;
                         }
     
                         $coupon->best_coupon = 1;
                         $publish_date = date('Y-m-d');
                         $timestamp = strtotime($publish_date);
                         $nextMonthTimestamp = strtotime('+1 month', $timestamp);
                         $expiry_date = date('Y-m-d', $nextMonthTimestamp);
                         $coupon->expiry_date = $expiry_date;
                         $coupon->active = 1;
                         $coupon->save();
     
                         $dataArray['new_coupons'][] = [
                             'ID' => $id,
                             'Coupon-Data' => $coupon->toArray()
                         ];
                     }
                 } catch (\Exception $e) {
                     $dataArray['failed'][] = [
                         'ID' => $id,
                         'Error' => $e->getMessage()
                     ];
                 }
             }
     
             fclose($fileHandle);
             $message = "Processing complete.\n";
     
             // Generate separate data report files
             $dataReportFiles = [
                 'brands' => ['new_brands', 'existing_brands', 'updated_brand_logos'],
                 'coupons' => ['new_coupons', 'existing_coupons'],
                 'categories' => ['new_categories', 'existing_categories'],
                 'failed' => ['failed']
             ];
     
             foreach ($dataReportFiles as $file => $keys) {
                 $reportData = [];
                 foreach ($keys as $key) {
                     $count = count($dataArray[$key]);
                     $keyWithCount = "{$key} (Total - {$count})";
                     $reportData[$keyWithCount] = array_values($dataArray[$key]);
                 }
                 $code = var_export($reportData, true);
                 $filePath = ucfirst($file) . '-Data-Report.php';
                 Storage::disk('admin')->put($filePath, '<?php return ' . $code . ';');
             }
     
             return $this->response()->success($message)->refresh();
         } else {
             return response()->json(['message' => 'Invalid selection value.'], 400);
         }
     }
     
     

    public function form()
    {
        $this->file('file', 'Upload CSV File')->rules('mimes:csv,txt');
        $this->hidden('selected_value')->value(6);        
        $this->interactor->form->model = new BrandModel();
    }
  
    public function html()
    {
        $url = url('/upload-form'); 
        
        return <<<HTML
        <a  class="btn btn-sm btn-warning fetch-brand"><i class='icon-file-import'></i>All Data Fetch</a>
        HTML;
    }
    

}


