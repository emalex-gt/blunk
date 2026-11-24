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
}
