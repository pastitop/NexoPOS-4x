<?php

namespace App\Jobs;

use App\Events\DashboardDayAfterUpdatedEvent;
use App\Events\ExpenseAfterRefreshEvent;
use App\Events\ExpenseBeforeRefreshEvent;
use App\Events\ExpenseHistoryAfterCreatedEvent;
use App\Events\ExpenseHistoryBeforeDeleteEvent;
use App\Models\DashboardDay;
use App\Services\DateService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ComputeDashboardExpensesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $event;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct( $event )
    {
        $this->event    =   $event;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        /**
         * @var ReportService
         */
        $reportService      =   app()->make( ReportService::class );

        /**
         * @var DateService
         */
        $dateService        =   app()->make( DateService::class );
        
        /**
         * @var Carbon
         */
        $now                =   now();

        $todayStart         =   Carbon::parse( $this->event->expenseHistory->created_at )->startOfDay()->toDateTimeString();
        $todayEnd           =   Carbon::parse( $this->event->expenseHistory->created_at )->endOfDay()->toDateTimeString();
        $dashboardDay       =   DashboardDay::from( $todayStart )
            ->to( $todayEnd )
            ->first();

        /**
         * According to the event that is triggered
         * we'll editer reduce the expense or increase
         */
        if ( $dashboardDay instanceof DashboardDay ) {
            if ( $this->event instanceof ExpenseHistoryAfterCreatedEvent ) {
                $reportService->increaseDailyExpenses( $this->event->expenseHistory, $dashboardDay );
            } else if ( $this->event instanceof ExpenseHistoryBeforeDeleteEvent ) {
                $reportService->reduceDailyExpenses( $this->event->expenseHistory, $dashboardDay );
            }

            $reports         =   DashboardDay::from( 
                Carbon::parse( $this->event->expenseHistory->created_at )->startOfDay()->toDateTimeString() 
            )->to(
                $dateService->copy()->endOfDay()->toDateTimeString()
            )->get();
    
            /**
             * Updates taxes form
             * the day the expense has been created
             */
            $reports->each( function( $dashboardDay ) use ( &$now ) {
                event( new ExpenseBeforeRefreshEvent( $dashboardDay, $now ) );
                $now->addMinute();
            });

            event( new ExpenseAfterRefreshEvent( $this->event, $now->addSeconds(10 ) ) );
            event( new DashboardDayAfterUpdatedEvent );
        }
    }
}
