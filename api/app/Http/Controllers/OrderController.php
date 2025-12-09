<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\CartItem;

class OrderController extends Controller
{
    /**
     * Lấy danh sách đơn hàng của user hiện tại
     */
    public function index()
    {
        $orders = Order::with(['items.product']) // Eager load để lấy luôn thông tin sản phẩm
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'orders' => $orders
        ]);
    }

    /**
     * Tạo đơn hàng mới (Checkout từ giỏ hàng)
     */
    public function checkout(Request $request)
    {
        // 1. Validate dữ liệu đầu vào (ví dụ địa chỉ giao hàng)
        $request->validate([
            'address' => 'required|string|max:255', 
            'payment_method' => 'required|string'
        ]);

        // 2. Lấy các sản phẩm trong giỏ hàng của user
        $cartItems = CartItem::with('product')
            ->where('user_id', auth()->id())
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json(['error' => 'Cart is empty'], 400);
        }

        // 3. Tính tổng tiền (Total)
        $total = 0;
        foreach ($cartItems as $item) {
            // Giả sử model Product có trường 'price'
            $total += $item->product->price * $item->quantity;
        }

        // 4. Sử dụng Transaction để đảm bảo an toàn dữ liệu
        DB::beginTransaction();

        try {
            // A. Tạo Order
            $order = Order::create([
                'user_id'    => auth()->id(),
                'total'      => $total,
                'status'     => 'pending', // Trạng thái mặc định
                'shipping_address' => $request->address, // Map dữ liệu từ Angular vào cột DB
                'payment_method' => $request->payment_method
            ]);

            // B. Tạo Order Items (Chuyển từ CartItem sang OrderItem)
            foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'price'      => $item->product->price, // Quan trọng: Lưu giá tại thời điểm mua
                ]);
            }

            // C. Xóa giỏ hàng sau khi đặt thành công
            CartItem::where('user_id', auth()->id())->delete();

            // Commit transaction
            DB::commit();

            return response()->json([
                'message' => 'Order created successfully',
                'order_id' => $order->id
            ], 201);

        } catch (\Exception $e) {
            // Nếu có lỗi, hoàn tác mọi thay đổi
            DB::rollBack();
            return response()->json(['error' => 'Order failed', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Xem chi tiết một đơn hàng cụ thể
     */
    public function show($id)
    {
        $order = Order::with(['items.product', 'address', 'payment'])
            ->where('user_id', auth()->id())
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'order' => $order
        ]);
    }
}