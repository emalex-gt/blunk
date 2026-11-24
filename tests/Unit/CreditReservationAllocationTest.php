<?php
namespace Tests\Unit;
use App\Services\Credits\CreditReservationAllocation;
use PHPUnit\Framework\TestCase;
class CreditReservationAllocationTest extends TestCase {
 public function test_partial_and_reinvoice_preserve_snapshot(): void {
  $e=new CreditReservationAllocation; $s=['quantity'=>'2.2500','gross'=>'58.45','line_discount'=>'0.00','general_discount'=>'0.45','net'=>'58.00'];
  $a=$e->allocate($s,'1.2500'); $b=$e->allocate($s,'1.0000',[$a]);
  $this->assertSame('32.22',$a['net']); $this->assertSame('25.78',$b['net']);
  $again=$e->allocate($s,'1.2500',[$b]); $this->assertSame('32.22',$again['net']);
 }
 public function test_last_partial_absorbs_cents_without_negative_components(): void {
  $e=new CreditReservationAllocation; $s=['quantity'=>'3.0000','gross'=>'12.00','line_discount'=>'0.00','general_discount'=>'2.00','net'=>'10.00'];
  $a=$e->allocate($s,'1.0000');$b=$e->allocate($s,'1.0000',[$a]);$c=$e->allocate($s,'1.0000',[$a,$b]);
  $this->assertSame('3.33',$a['net']);$this->assertSame('3.33',$b['net']);$this->assertSame('3.34',$c['net']);
 }
 public function test_handles_decimal_quantities_and_full_discount(): void {
  $e=new CreditReservationAllocation; $s=['quantity'=>'1.0005','gross'=>'0.02','line_discount'=>'0.01','general_discount'=>'0.01','net'=>'0.00'];
  $a=$e->allocate($s,'0.5000'); $b=$e->allocate($s,'0.5005',[$a]);
  $this->assertSame('0.00',$a['net']); $this->assertSame('0.00',$b['net']);
  $this->assertGreaterThanOrEqual(0,(float)$a['gross']);
 }
 public function test_rejects_partial_quantity_above_available(): void {
  $this->expectException(\InvalidArgumentException::class);
  (new CreditReservationAllocation)->allocate(['quantity'=>'1.0000','gross'=>'1.00','line_discount'=>'0.00','general_discount'=>'0.00','net'=>'1.00'],'1.0001');
 }
 public function test_snapshot_distributes_general_discount_after_line_discounts(): void {
  $snapshot=(new CreditReservationAllocation)->snapshot([
   ['quantity'=>'1.0000','unit_price'=>'42.15','line_discount_amount'=>'0.05'],
   ['quantity'=>'1.0005','unit_price'=>'16.30','line_discount_amount'=>'0.00'],
  ],'0.45');
  $this->assertSame('58.46',$snapshot['subtotal']);
  $this->assertSame('0.05',$snapshot['line_discount_amount']);
  $this->assertSame('0.45',$snapshot['general_discount_amount']);
  $this->assertSame('57.96',$snapshot['total']);
  $this->assertSame('0.45',bcadd($snapshot['lines'][0]['general_discount_amount'],$snapshot['lines'][1]['general_discount_amount'],2));
  foreach ($snapshot['lines'] as $line) $this->assertSame($line['subtotal'],bcadd($line['line_discount_amount'],bcadd($line['general_discount_amount'],$line['net_amount'],2),2));
 }
}
