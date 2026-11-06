<?php
/**
 * Golden output for the attendee-question model: the 3.44.2 New order email
 * answers, the free admin notice lines, the question controls and the
 * question-row editor. These literals were captured from the code BEFORE the
 * Anchor_Questions extraction and must stay green, unchanged, through it.
 *
 * @package Anchor\Events\Tests
 */

use Anchor\Events\Admin_Email_Answers;
use Anchor\Events\Module;

/**
 * @group questions
 * @group email
 */
class Test_Questions_Golden extends Anchor_Events_TestCase {

	private function questions() {
		return [
			[ 'key' => 'heard', 'label' => 'How did you hear about us?', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => true ],
			[ 'key' => 'diet', 'label' => 'Dietary restrictions?', 'type' => 'multiselect', 'options' => [ 'Vegan', 'Gluten-free', 'Other' ], 'required' => false, 'admin_email' => true ],
			[ 'key' => 'secret', 'label' => 'Private note', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => false ],
			[ 'key' => 'legacy', 'label' => 'Legacy question', 'type' => 'text', 'options' => [], 'required' => false ],
			[ 'key' => 'empty_q', 'label' => 'Left blank', 'type' => 'text', 'options' => [], 'required' => false, 'admin_email' => true ],
		];
	}

	private function golden_event() {
		$event_id = $this->make_event( [ 'title' => 'Golden Event' ] );
		update_post_meta( $event_id, Module::QUESTIONS_META, $this->questions() );
		return $event_id;
	}

	private function golden_order( $event_id ) {
		$attendees = [
			1 => [ 'name' => 'Ann', 'fields' => [ 'heard' => 'A friend', 'diet' => 'Vegan, Other: shellfish' ] ],
			2 => [ 'name' => '', 'fields' => [ 'heard' => 'R&D <b>' ] ],
		];
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Ticket' );
		$item->set_quantity( 2 );
		$item->update_meta_data( '_anchor_event_id', $event_id );
		$item->update_meta_data( '_anchor_attendees', $attendees );
		$order->add_item( $item );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	private function render_email( $plain_text ) {
		$this->require_wc();
		$order = $this->golden_order( $this->golden_event() );
		ob_start();
		$this->woocommerce()->render_new_order_email_answers( $order, true, $plain_text, (object) [ 'id' => 'new_order' ] );
		return (string) ob_get_clean();
	}

	public function test_new_order_email_html_is_unchanged() {
		$this->assertSame( '<h2>Golden Event</h2><p style="margin:0 0 12px;"><strong>Ann</strong><br />How did you hear about us?: A friend<br />Dietary restrictions?: Vegan, Other: shellfish</p><p style="margin:0 0 12px;"><strong>Guest</strong><br />How did you hear about us?: R&amp;D &lt;b&gt;</p>', $this->render_email( false ) );
	}

	public function test_new_order_email_plain_is_unchanged() {
		$this->assertSame( '
Golden Event

Ann
How did you hear about us?: A friend
Dietary restrictions?: Vegan, Other: shellfish

Guest
How did you hear about us?: R&D <b>
', $this->render_email( true ) );
	}

	public function test_free_admin_notice_answer_lines_are_unchanged() {
		$event_id = $this->golden_event();
		$seat_id  = $this->make_seat( $event_id, [ 'reg_fields' => [ 'heard' => 'A friend', 'diet' => 'Vegan, Other: shellfish', 'secret' => 'x' ] ] );
		$this->assertSame(
			[ 'How did you hear about us?: A friend', 'Dietary restrictions?: Vegan, Other: shellfish' ],
			Admin_Email_Answers::text_lines( $this->module(), $event_id, $seat_id )
		);
	}

	public function test_question_control_markup_is_unchanged() {
		$qs   = [
			'text'        => [ 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'options' => [], 'required' => true ],
			'textarea'    => [ 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'options' => [], 'required' => false ],
			'select'      => [ 'key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => [ 'S', 'M' ], 'required' => true ],
			'multiselect' => [ 'key' => 'diet', 'label' => 'Diet', 'type' => 'multiselect', 'options' => [ 'Vegan', 'Gluten-free', 'Other' ], 'required' => false ],
			'checkbox'    => [ 'key' => 'agree', 'label' => 'Agree', 'type' => 'checkbox', 'options' => [], 'required' => false ],
		];
		$vals = [ 'text' => 'A "q" <b>', 'textarea' => "l1\n<i>l2", 'select' => 'M', 'multiselect' => 'Vegan, Other: shellfish', 'checkbox' => 'yes' ];
		$want = [
			'text' => '<input type="text" id="f-name" class="cls" name="x[fields][name]" value="A &quot;q&quot; &lt;b&gt;" required />',
			'textarea' => '<textarea id="f-notes" class="cls" name="x[fields][notes]" rows="3">l1
&lt;i&gt;l2</textarea>',
			'select' => '<select id="f-size" class="cls" name="x[fields][size]" required><option value="">— Select —</option><option value="S">S</option><option value="M" selected=\'selected\'>M</option></select>',
			'multiselect' => '<details class="anchor-event-multi" id="f-diet" role="group" aria-label="Diet"><summary class="anchor-event-multi__summary">Vegan, Other: shellfish</summary><div class="anchor-event-multi__list"><label class="anchor-event-multi__opt"><input type="checkbox" name="x[fields][diet][]" value="Vegan" checked /> Vegan</label><label class="anchor-event-multi__opt"><input type="checkbox" name="x[fields][diet][]" value="Gluten-free" /> Gluten-free</label><label class="anchor-event-multi__opt"><input type="checkbox" name="x[fields][diet][]" value="Other" checked /> Other</label><input type="text" class="anchor-event-multi__other cls" name="x[fields][diet__other]" value="shellfish" placeholder="Please specify" aria-label="Other" /></div></details>',
			'checkbox' => '<label class="lbl"><input type="checkbox" id="f-agree" class="cls" name="x[fields][agree]" value="yes" checked=\'checked\' /> I agree</label>',
			'checkbox_bare' => '<input type="checkbox" name="agree" value="yes" />',
		];
		foreach ( $qs as $type => $q ) {
			$got = $this->module()->render_registration_question_control( $q, [
				'name'           => 'x[fields][' . $q['key'] . ']',
				'id'             => 'f-' . $q['key'],
				'value'          => $vals[ $type ],
				'class'          => 'cls',
				'checkbox_label' => 'I agree',
				'checkbox_class' => 'lbl',
			] );
			$this->assertSame( $want[ $type ], $got, $type );
		}
		$this->assertSame( $want['checkbox_bare'], $this->module()->render_registration_question_control( $qs['checkbox'], [ 'name' => 'agree' ] ) );
	}

	public function test_question_row_editor_markup_is_unchanged() {
		$method = new ReflectionMethod( $this->module(), 'event_question_row_html' );
		$method->setAccessible( true );
		$row = [ 'key' => 'diet', 'label' => 'Diet <x>', 'type' => 'multiselect', 'options' => [ 'Vegan', 'Other' ], 'required' => true, 'admin_email' => true ];
		$this->assertSame( '        <tr class="anchor-question-row anchor-event-question-row">
            <td>
                <input type="text" name="anchor_event_questions[0][label]" value="Diet &lt;x&gt;" placeholder="e.g. Company or organization" />
                <input type="hidden" name="anchor_event_questions[0][key]" value="diet" />
            </td>
            <td>
                <select name="anchor_event_questions[0][type]" class="anchor-question-type">
                    <option value="text" >Short text</option>
                    <option value="textarea" >Long text</option>
                    <option value="select" >Choose one</option>
                    <option value="multiselect"  selected=\'selected\'>Choose any</option>
                    <option value="checkbox" >Yes / no</option>
                </select>
            </td>
            <td>
                <textarea name="anchor_event_questions[0][options]" rows="2" placeholder="One choice per line">Vegan
Other</textarea>
            </td>
            <td>
                <label><input type="checkbox" name="anchor_event_questions[0][required]" value="1"  checked=\'checked\' /> Required</label>
                <label><input type="checkbox" name="anchor_event_questions[0][admin_email]" value="1"  checked=\'checked\' /> Include in admin email</label>
                <span class="anchor-question-help">Answers are sent by plain email. Don&#039;t tick this for health information.</span>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-question-remove anchor-event-question-remove" aria-label="Remove question">&times;</button>
            </td>
        </tr>
        ', $method->invoke( $this->module(), 0, $row ) );
		$this->assertSame( '        <tr class="anchor-question-row anchor-event-question-row">
            <td>
                <input type="text" name="anchor_event_questions[__INDEX__][label]" value="" placeholder="e.g. Company or organization" />
                <input type="hidden" name="anchor_event_questions[__INDEX__][key]" value="" />
            </td>
            <td>
                <select name="anchor_event_questions[__INDEX__][type]" class="anchor-question-type">
                    <option value="text"  selected=\'selected\'>Short text</option>
                    <option value="textarea" >Long text</option>
                    <option value="select" >Choose one</option>
                    <option value="multiselect" >Choose any</option>
                    <option value="checkbox" >Yes / no</option>
                </select>
            </td>
            <td>
                <textarea name="anchor_event_questions[__INDEX__][options]" rows="2" placeholder="One choice per line"></textarea>
            </td>
            <td>
                <label><input type="checkbox" name="anchor_event_questions[__INDEX__][required]" value="1"  /> Required</label>
                <label><input type="checkbox" name="anchor_event_questions[__INDEX__][admin_email]" value="1"  /> Include in admin email</label>
                <span class="anchor-question-help">Answers are sent by plain email. Don&#039;t tick this for health information.</span>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-question-remove anchor-event-question-remove" aria-label="Remove question">&times;</button>
            </td>
        </tr>
        ', $method->invoke( $this->module(), 0, null, true ) );
	}

	public function test_repeater_markup_with_events_prefix_is_unchanged() {
		$alias = new ReflectionMethod( $this->module(), 'question_alias_classes' );
		$alias->setAccessible( true );
		$rows = [
			[ 'key' => 'a', 'label' => 'A', 'type' => 'text' ],
			[ 'key' => 'b', 'label' => 'B', 'type' => 'select', 'options' => [ 'x' ] ],
		];
		$html = Anchor_Questions_Editor::repeater_html( 'anchor_event_questions', $rows, $alias->invoke( null ) );
		$this->assertSame( '                <div class="anchor-questions-editor anchor-event-questions" data-prefix="anchor_event_questions">
                                        <input type="hidden" name="anchor_event_questions_present" value="1" />
                    <table class="widefat anchor-questions-table anchor-event-questions-table">
                        <thead>
                            <tr>
                                <th>Question</th>
                                <th>Answer type</th>
                                <th>Choices</th>
                                <th>Required</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody class="anchor-questions-rows anchor-event-questions-rows">
                                                                    <tr class="anchor-question-row anchor-event-question-row">
            <td>
                <input type="text" name="anchor_event_questions[0][label]" value="A" placeholder="e.g. Company or organization" />
                <input type="hidden" name="anchor_event_questions[0][key]" value="a" />
            </td>
            <td>
                <select name="anchor_event_questions[0][type]" class="anchor-question-type">
                    <option value="text"  selected=\'selected\'>Short text</option>
                    <option value="textarea" >Long text</option>
                    <option value="select" >Choose one</option>
                    <option value="multiselect" >Choose any</option>
                    <option value="checkbox" >Yes / no</option>
                </select>
            </td>
            <td>
                <textarea name="anchor_event_questions[0][options]" rows="2" placeholder="One choice per line"></textarea>
            </td>
            <td>
                <label><input type="checkbox" name="anchor_event_questions[0][required]" value="1"  /> Required</label>
                <label><input type="checkbox" name="anchor_event_questions[0][admin_email]" value="1"  /> Include in admin email</label>
                <span class="anchor-question-help">Answers are sent by plain email. Don&#039;t tick this for health information.</span>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-question-remove anchor-event-question-remove" aria-label="Remove question">&times;</button>
            </td>
        </tr>
                                                                            <tr class="anchor-question-row anchor-event-question-row">
            <td>
                <input type="text" name="anchor_event_questions[1][label]" value="B" placeholder="e.g. Company or organization" />
                <input type="hidden" name="anchor_event_questions[1][key]" value="b" />
            </td>
            <td>
                <select name="anchor_event_questions[1][type]" class="anchor-question-type">
                    <option value="text" >Short text</option>
                    <option value="textarea" >Long text</option>
                    <option value="select"  selected=\'selected\'>Choose one</option>
                    <option value="multiselect" >Choose any</option>
                    <option value="checkbox" >Yes / no</option>
                </select>
            </td>
            <td>
                <textarea name="anchor_event_questions[1][options]" rows="2" placeholder="One choice per line">x</textarea>
            </td>
            <td>
                <label><input type="checkbox" name="anchor_event_questions[1][required]" value="1"  /> Required</label>
                <label><input type="checkbox" name="anchor_event_questions[1][admin_email]" value="1"  /> Include in admin email</label>
                <span class="anchor-question-help">Answers are sent by plain email. Don&#039;t tick this for health information.</span>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-question-remove anchor-event-question-remove" aria-label="Remove question">&times;</button>
            </td>
        </tr>
                                                            </tbody>
                    </table>
                    <p>
                        <button type="button" class="anchor-event-button-secondary anchor-question-add anchor-event-question-add">Add question</button>
                    </p>
                    <script type="text/template" class="anchor-question-template" id="anchor-event-question-template">
                                <tr class="anchor-question-row anchor-event-question-row">
            <td>
                <input type="text" name="anchor_event_questions[__INDEX__][label]" value="" placeholder="e.g. Company or organization" />
                <input type="hidden" name="anchor_event_questions[__INDEX__][key]" value="" />
            </td>
            <td>
                <select name="anchor_event_questions[__INDEX__][type]" class="anchor-question-type">
                    <option value="text"  selected=\'selected\'>Short text</option>
                    <option value="textarea" >Long text</option>
                    <option value="select" >Choose one</option>
                    <option value="multiselect" >Choose any</option>
                    <option value="checkbox" >Yes / no</option>
                </select>
            </td>
            <td>
                <textarea name="anchor_event_questions[__INDEX__][options]" rows="2" placeholder="One choice per line"></textarea>
            </td>
            <td>
                <label><input type="checkbox" name="anchor_event_questions[__INDEX__][required]" value="1"  /> Required</label>
                <label><input type="checkbox" name="anchor_event_questions[__INDEX__][admin_email]" value="1"  /> Include in admin email</label>
                <span class="anchor-question-help">Answers are sent by plain email. Don&#039;t tick this for health information.</span>
            </td>
            <td>
                <button type="button" class="button-link-delete anchor-question-remove anchor-event-question-remove" aria-label="Remove question">&times;</button>
            </td>
        </tr>
                            </script>
                </div>
        ', $html );
	}
}
