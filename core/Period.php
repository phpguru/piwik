<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

/**
 * Creating a new Piwik_Period subclass:
 *
 * Every overloaded method must start with the code
 *        if(!$this->subperiodsProcessed)
 *        {
 *            $this->generate();
 *        }
 *    that checks whether the subperiods have already been computed.
 *    This is for performance improvements, computing the subperiods is done a per demand basis.
 *
 * @package Piwik
 * @subpackage Piwik_Period
 */
abstract class Piwik_Period
{
    /**
     * Array of subperiods
     * @var Piwik_Period[]
     */
    protected $subperiods = array();
    protected $subperiodsProcessed = false;

    /**
     * @var string
     */
    protected $label = null;

    /**
     * @var Piwik_Date
     */
    protected $date = null;
    static protected $errorAvailablePeriods = 'day, week, month, year, range';

    /**
     * Constructor
     * @param Piwik_Date $date
     */
    public function __construct($date)
    {
        $this->checkInputDate($date);
        $this->date = clone $date;
    }

    /**
     * @param string $strPeriod "day", "week", "month", "year"
     * @param Piwik_Date $date Piwik_Date object
     * @throws Exception
     * @return Piwik_Period
     */
    static public function factory($strPeriod, Piwik_Date $date)
    {
        switch ($strPeriod) {
            case 'day':
                return new Piwik_Period_Day($date);
                break;

            case 'week':
                return new Piwik_Period_Week($date);
                break;

            case 'month':
                return new Piwik_Period_Month($date);
                break;

            case 'year':
                return new Piwik_Period_Year($date);
                break;

            default:
                throw new Exception(Piwik_TranslateException('General_ExceptionInvalidPeriod', array($strPeriod, self::$errorAvailablePeriods)));
                break;
        }
    }


    /**
     * Indicate if $dateString and $period correspond to multiple periods
     *
     * @static
     * @param  $dateString
     * @param  $period
     * @return boolean
     */
    public static function isMultiplePeriod($dateString, $period)
    {
        return
            is_string($dateString)
            && (preg_match('/^(last|previous){1}([0-9]*)$/D', $dateString, $regs)
            || Piwik_Period_Range::parseDateRange($dateString))
            && $period != 'range';
    }

    /**
     * The advanced factory method is easier to use from the API than the factory
     * method above. It doesn't require an instance of Piwik_Date and works for
     * period=range. Generally speaking, anything that can be passed as period
     * and range to the API methods can directly be forwarded to this factory
     * method in order to get a suitable instance of Piwik_Period.
     *
     * @param string $strPeriod "day", "week", "month", "year", "range"
     * @param string $strDate
     * @return Piwik_Period
     */
    static public function advancedFactory($strPeriod, $strDate)
    {
        if (Piwik_Period::isMultiplePeriod($strDate, $strPeriod) || $strPeriod == 'range') {
            return new Piwik_Period_Range($strPeriod, $strDate);
        }
        return Piwik_Period::factory($strPeriod, Piwik_Date::factory($strDate));
    }


    /**
     * Creates a period instance using a Piwik_Site instance and two strings describing
     * the period & date.
     *
     * @param string $timezone
     * @param string $period The period string: day, week, month, year, range
     * @param string $date The date or date range string.
     * @return Piwik_Period
     */
    public static function makePeriodFromQueryParams($timezone, $period, $date)
    {
        if (empty($timezone)) {
            $timezone = 'UTC';
        }

        if ($period == 'range') {
            $oPeriod = new Piwik_Period_Range('range', $date, $timezone, Piwik_Date::factory('today', $timezone));
        } else {
            if (!($date instanceof Piwik_Date)) {
                if ($date == 'now' || $date == 'today') {
                    $date = date('Y-m-d', Piwik_Date::factory('now', $timezone)->getTimestamp());
                } elseif ($date == 'yesterday' || $date == 'yesterdaySameTime' ) {
                    $date = date('Y-m-d', Piwik_Date::factory('now', $timezone)->subDay(1)->getTimestamp());
                }
                $date = Piwik_Date::factory( $date );
            }
            $oPeriod = Piwik_Period::factory($period, $date);
        }
        return $oPeriod;
    }

    /**
     * Returns the first day of the period
     *
     * @return Piwik_Date First day of the period
     */
    public function getDateStart()
    {
        if (!$this->subperiodsProcessed) {
            $this->generate();
        }
        if (count($this->subperiods) == 0) {
            return $this->getDate();
        }
        $periods = $this->getSubperiods();
        $currentPeriod = $periods[0];
        while ($currentPeriod->getNumberOfSubperiods() > 0) {
            $periods = $currentPeriod->getSubperiods();
            $currentPeriod = $periods[0];
        }
        return $currentPeriod->getDate();
    }

    /**
     * Returns the last day of the period ; can be a date in the future
     *
     * @return Piwik_Date Last day of the period
     */
    public function getDateEnd()
    {
        if (!$this->subperiodsProcessed) {
            $this->generate();
        }
        if (count($this->subperiods) == 0) {
            return $this->getDate();
        }
        $periods = $this->getSubperiods();
        $currentPeriod = $periods[count($periods) - 1];
        while ($currentPeriod->getNumberOfSubperiods() > 0) {
            $periods = $currentPeriod->getSubperiods();
            $currentPeriod = $periods[count($periods) - 1];
        }
        return $currentPeriod->getDate();
    }

    public function getId()
    {
        return Piwik::$idPeriods[$this->getLabel()];
    }

    /**
     * Returns the label for the current period
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @return Piwik_Date
     */
    protected function getDate()
    {
        return $this->date;
    }

    /**
     * Checks if the given date is an instance of Piwik_Date
     *
     * @param Piwik_Date $date
     *
     * @throws Exception
     */
    protected function checkInputDate($date)
    {
        if (!($date instanceof Piwik_Date)) {
            throw new Exception("The date must be a Piwik_Date object. " . var_export($date, true));
        }
    }

    protected function generate()
    {
        $this->subperiodsProcessed = true;
    }

    /**
     * Returns the number of available subperiods
     * @return int
     */
    public function getNumberOfSubperiods()
    {
        if (!$this->subperiodsProcessed) {
            $this->generate();
        }
        return count($this->subperiods);
    }

    /**
     * Returns Period_Day for a period made of days (week, month),
     *            Period_Month for a period made of months (year)
     *
     * @return array Piwik_Period
     */
    public function getSubperiods()
    {
        if (!$this->subperiodsProcessed) {
            $this->generate();
        }
        return $this->subperiods;
    }

    /**
     * Add a date to the period.
     *
     * Protected because it not yet supported to add periods after the initialization
     *
     * @param Piwik_Period $period Valid Piwik_Period object
     */
    protected function addSubperiod($period)
    {
        $this->subperiods[] = $period;
    }

    /**
     * Returns a string representing the current period
     * Given param will be used to format the returned value
     *
     * @param string $format
     *
     * @return array
     */
    public function toString($format = "Y-m-d")
    {
        if (!$this->subperiodsProcessed) {
            $this->generate();
        }
        $dateString = array();
        foreach ($this->subperiods as $period) {
            $dateString[] = $period->toString($format);
        }
        return $dateString;
    }

    public function __toString()
    {
        return implode(",", $this->toString());
    }

    public function get($part = null)
    {
        if (!$this->subperiodsProcessed) {
            $this->generate();
        }
        return $this->date->toString($part);
    }

    abstract public function getPrettyString();

    abstract public function getLocalizedShortString();

    abstract public function getLocalizedLongString();
    
    public function getRangeString()
    {
        return $this->getDateStart()->toString("Y-m-d").",".$this->getDateEnd()->toString("Y-m-d");
    }
}
