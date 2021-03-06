<?php

namespace Torg\Stocks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Prettus\Repository\Contracts\Transformable;
use Prettus\Repository\Traits\TransformableTrait;
use Torg\Base\Warehouse;
use Torg\Catalog\Product;
use Torg\Stocks\Exceptions\StockException;

/**
 * Torg\Stocks\Stock
 *
 * @property integer $id
 * @property integer $product_id
 * @property string $stock_code
 * @property string $stock_box
 * @property integer $warehouse_id
 * @property integer $company_id
 * @property float $qty
 * @property float $reserved
 * @property float $available
 * @property float $min_qty
 * @property float $ideal_qty
 * @property float $total
 * @property float $weight
 * @property float $volume
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property string $deleted_at
 * @property-read \Torg\Catalog\Product $product
 * @property-read \Torg\Base\Warehouse $warehouse
 * @method static Builder|Stock ofProduct($product)
 * @method static Builder|Stock ofWarehouse($warehouse)
 * @mixin \Eloquent
 * @method static Builder|Stock whereId($value)
 * @method static Builder|Stock whereProductId($value)
 * @method static Builder|Stock whereStockCode($value)
 * @method static Builder|Stock whereStockBox($value)
 * @method static Builder|Stock whereWarehouseId($value)
 * @method static Builder|Stock whereQty($value)
 * @method static Builder|Stock whereReserved($value)
 * @method static Builder|Stock whereAvailable($value)
 * @method static Builder|Stock whereMinQty($value)
 * @method static Builder|Stock whereIdealQty($value)
 * @method static Builder|Stock whereTotal($value)
 * @method static Builder|Stock whereWeight($value)
 * @method static Builder|Stock whereVolume($value)
 * @method static Builder|Stock whereCreatedAt($value)
 * @method static Builder|Stock whereUpdatedAt($value)
 * @method static Builder|Stock whereDeletedAt($value)
 */
class Stock extends Model implements Transformable
{

    use SoftDeletes, TransformableTrait;

    /**
     * @var array
     */
    protected  $with = ['warehouse', 'product'];

    /**
     * @var array
     */
    protected  $fillable = [
        'qty',
        'available',
        'reserved',
        'min_qty',
        'ideal_qty',
        'weight',
        'volume',
        'warehouse_id',
        'product_id',
        'stock_code',
    ];

    /**
     * @var array
     */
    protected  $attributes = array(
        'qty' => 0,
        'available' => 0,
        'reserved' => 0,
        'min_qty' => 0,
        'ideal_qty' => 0,
        'weight' => 0,
        'volume' => 0,
    );

    /**
     * @var array
     */
    public static $rules = [];

    /**
     *
     * @throws \Torg\Stocks\Exceptions\StockException
     */
    public static function boot()
    {
        parent::boot();

        static::creating(
            function (Stock $stock) {
                $stock->checkExistsStock();
            }
        );

        static::updating(
            function (Stock $stock) {
                $stock->checkChangeWarehouse();
            }
        );
    }

    /**
     * todo вынести в валидатор
     *
     * @throws StockException
     */
    public function checkExistsStock()
    {
        $exists = Stock::where('warehouse_id', $this->warehouse_id)
            ->where('product_id', $this->product_id)->first();

        if ($exists instanceof Stock) {
            throw new StockException(
                'Stock already exists. Use StockRepository::findOrCreate method for Stock creating'
            );
        }
    }

    /**
     *
     */
    public function checkChangeWarehouse()
    {

        
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {

        return $this->belongsTo(Product::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @param float $qty
     *
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function setQtyAttribute($qty)
    {

        $qty = static::castQty($qty);

        $this->attributes['qty'] = $qty;
        $this->calcAvailable();
        $this->calcTotals();

        return $this;
    }

    /**
     * Проверяет доступно ли запрашиваемое кол-во товара
     *
     * @param $qty
     *
     * @return bool
     */
    public function checkAvailable($qty)
    {
        if ($this->available >= $qty) {
            return true;
        }

        return false;
    }

    /**
     * Пересчитывает доступное кол-во, на основании общего кол-ва на складе и резервов
     * @return $this
     */
    public function calcAvailable()
    {

        $this->available = $this->qty - $this->reserved;

        return $this;
    }

    /**
     * @todo написать реализацию метода calcTotals
     * Пересчитывает общую стоимость товаров на складе, объем и вес
     * @return $this
     */

    public function calcTotals()
    {

        return $this;
    }

    /**
     * todo добавить проверку на списание свыше резервов
     * Уменьшает кол-во товара на складе
     *
     * @param $qty
     *
     * @return $this
     * @throws \InvalidArgumentException
     * @throws StockException
     */
    public function decreaseQty($qty)
    {

        $qty = static::castQty($qty);
        if ($this->qty < $qty) {
            throw new StockException('Недостаточно товара для списания');
        }

        $this->qty -= $qty;

        return $this;
    }

    /**
     *
     * Увеличивает кол-во товара на складе
     *
     * @param $qty
     *
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function increaseQty($qty)
    {

        $qty = static::castQty($qty);

        $this->qty += $qty;

        return $this;
    }

    /**
     * @todo этот метод должен вызываться только из документа резерва
     * Резервирует заданное кол-во товара
     *
     * @param $qty
     *
     * @return $this
     * @throws \InvalidArgumentException
     * @throws StockException
     */
    public function reserveQty($qty)
    {
        $qty = static::castQty($qty);

        if ($this->available < $qty) {
            throw new StockException('Недостаточно товара для резерва');
        }

        $this->reserved += + $qty;

        $this->calcAvailable();
        $this->calcTotals();

        return $this;

    }

    /**
     * Снимаем резерв заданного кол-ва
     *
     * @param $qty
     *
     * @return $this
     * @throws \InvalidArgumentException
     * @throws StockException
     */
    public function removeReserveQty($qty)
    {
        $qty = static::castQty($qty);

        if ($this->reserved < $qty) {
            throw new StockException('Невозможно снять с резерва больше чем уже стоит в резерве');
        }

        $this->reserved -= - $qty;

        $this->calcAvailable();
        $this->calcTotals();

        return $this;
    }

    /**
     * @param $qty
     *
     * @return float
     * @throws \InvalidArgumentException
     */
    public static function castQty($qty)
    {
        if (!is_numeric($qty)) {
            throw new InvalidArgumentException('кол-во товара для резерва должно быть числом');
        }

        return (float)$qty;
    }

}
