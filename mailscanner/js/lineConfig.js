/**
 * Modern ECharts Line/Area/Bar Chart Implementation
 * Matching https://ip.space.ua/info.php & EFA-NG Design
 * Full Dual Y-Axis Support (Left: Message Counts, Right: Volume/Bytes)
 */

var lineColors = {
  mailColor: '#1f6cb0',   // Primary Blue
  spamColor: '#f59e0b',   // Amber
  virusColor: '#dc2626',  // Red
  volumeColor: '#0284c7', // Sky Blue
  mcpColor: '#8b5cf6',    // Purple
  hamColor: '#10b981'     // Green
};

function formatBytes(bytes) {
  if (bytes === 0 || isNaN(bytes)) return '0 B';
  var k = 1024;
  var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  var i = Math.floor(Math.log(Math.abs(bytes)) / Math.log(k));
  if (i < 0) i = 0;
  if (i >= sizes.length) i = sizes.length - 1;
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function printLineGraph(chartId, settings) {
  var chartDom = document.getElementById(chartId);
  if (!chartDom) return;

  if (typeof echarts === 'undefined') {
    console.error('ECharts library not loaded');
    return;
  }

  var existingChart = echarts.getInstanceByDom(chartDom);
  if (existingChart) {
    existingChart.dispose();
  }

  var myChart = echarts.init(chartDom);

  var isHeaderTraffic = (chartId === 'trafficgraph');
  var labels = settings.chartLabels || [];
  var seriesList = [];
  var legendData = [];
  var seriesMeta = [];

  var palette = ['#1f6cb0', '#f59e0b', '#dc2626', '#10b981', '#8b5cf6', '#06b6d4'];
  var hasMultipleAxes = (settings.chartNumericData && settings.chartNumericData.length > 1);

  if (settings.chartNumericData && settings.chartNumericData.length > 0) {
    var colorIdx = 0;
    for (var axis = 0; axis < settings.chartNumericData.length; axis++) {
      var axisData = settings.chartNumericData[axis];
      for (var s = 0; s < axisData.length; s++) {
        var sName = (settings.chartDataLabels && settings.chartDataLabels[axis] && settings.chartDataLabels[axis][s]) 
                    ? settings.chartDataLabels[axis][s] 
                    : ('Series ' + (s + 1));
        legendData.push(sName);

        var sType = (settings.types && settings.types[axis] && settings.types[axis][s]) 
                    ? settings.types[axis][s] 
                    : 'line';
        if (sType !== 'bar') {
          sType = 'line';
        }

        var colKey = '';
        if (settings.colors && settings.colors[axis] && settings.colors[axis][s]) {
          colKey = settings.colors[axis][s];
        }

        // Smart color matching for standard mail security entities
        var color = '';
        var lowerName = sName.toLowerCase();
        if (lowerName.indexOf('mail') !== -1 || lowerName.indexOf('email') !== -1) {
          color = lineColors.mailColor;
        } else if (lowerName.indexOf('virus') !== -1) {
          color = lineColors.virusColor;
        } else if (lowerName.indexOf('spam') !== -1) {
          color = lineColors.spamColor;
        } else if (lowerName.indexOf('volume') !== -1 || lowerName.indexOf('size') !== -1) {
          color = lineColors.volumeColor;
        } else if (lowerName.indexOf('mcp') !== -1) {
          color = lineColors.mcpColor;
        } else {
          color = lineColors[colKey] || palette[colorIdx % palette.length];
        }
        colorIdx++;

        var isFilled = (settings.fillBelowLine && settings.fillBelowLine[axis]);

        var seriesConfig = {
          name: sName,
          type: sType,
          yAxisIndex: axis, // Assigns series to appropriate Y-axis (0: Left for counts, 1: Right for volume)
          data: axisData[s],
          smooth: 0.35,
          showSymbol: !isHeaderTraffic && (sType === 'line'),
          symbolSize: 4,
          itemStyle: {
            color: color,
            borderRadius: sType === 'bar' ? [4, 4, 0, 0] : 0
          },
          lineStyle: {
            color: color,
            width: isHeaderTraffic ? 2 : 2.5
          }
        };

        if (isFilled || isHeaderTraffic) {
          seriesConfig.areaStyle = {
            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
              { offset: 0, color: hexToRgba(color, 0.35) },
              { offset: 1, color: hexToRgba(color, 0.01) }
            ])
          };
        }

        seriesMeta.push({ axis: axis, series: s, name: sName });
        seriesList.push(seriesConfig);
      }
    }
  }

  // Construct Y-Axes (Dual Axis support when multiple data categories exist)
  var yAxes = [];

  // Y-Axis 0 (Left: Messages / Counts)
  var leftAxisName = (settings.yAxeDescriptions && settings.yAxeDescriptions[0]) ? settings.yAxeDescriptions[0] : '';
  yAxes.push({
    type: 'value',
    name: isHeaderTraffic ? '' : leftAxisName,
    position: 'left',
    minInterval: 1,
    axisLine: {
      show: !isHeaderTraffic,
      lineStyle: { color: '#cbd5e1' }
    },
    axisTick: { show: false },
    splitLine: {
      show: true,
      lineStyle: {
        color: '#f1f5f9',
        type: 'dashed'
      }
    },
    axisLabel: {
      color: '#64748b',
      fontSize: isHeaderTraffic ? 9 : 10.5,
      formatter: function(val) {
        if (val >= 1000000) return (val / 1000000).toFixed(1) + 'M';
        if (val >= 1000) return (val / 1000).toFixed(0) + 'k';
        return val;
      }
    }
  });

  // Y-Axis 1 (Right: Volume / Bytes)
  if (hasMultipleAxes) {
    var rightAxisName = (settings.yAxeDescriptions && settings.yAxeDescriptions[1]) ? settings.yAxeDescriptions[1] : 'Volume';
    yAxes.push({
      type: 'value',
      name: rightAxisName,
      position: 'right',
      axisLine: {
        show: true,
        lineStyle: { color: '#cbd5e1' }
      },
      axisTick: { show: false },
      splitLine: {
        show: false
      },
      axisLabel: {
        color: '#64748b',
        fontSize: 10.5,
        formatter: function(val) {
          return formatBytes(val);
        }
      }
    });
  }

  var option = {
    title: {
      text: settings.chartTitle || '',
      left: 'center',
      top: 6,
      textStyle: {
        fontSize: 15,
        fontWeight: 700,
        color: '#0f172a'
      }
    },
    tooltip: {
      trigger: 'axis',
      axisPointer: {
        type: 'cross',
        label: {
          backgroundColor: '#1f6cb0'
        },
        lineStyle: {
          color: '#94a3b8',
          type: 'dashed'
        }
      },
      backgroundColor: 'rgba(15, 23, 42, 0.94)',
      borderColor: '#334155',
      borderWidth: 1,
      padding: [10, 14],
      textStyle: {
        color: '#f8fafc',
        fontSize: 12
      },
      formatter: function(params) {
        if (!params || !params.length) return '';
        var out = '<div style="font-weight:700;margin-bottom:6px;border-bottom:1px solid rgba(255,255,255,0.15);padding-bottom:3px;">' + params[0].axisValue + '</div>';
        params.forEach(function(item) {
          var val = item.value;
          var formattedVal = val;

          if (item.seriesIndex !== undefined && seriesMeta[item.seriesIndex]) {
            var meta = seriesMeta[item.seriesIndex];
            if (settings.chartFormattedData && 
                settings.chartFormattedData[meta.axis] && 
                settings.chartFormattedData[meta.axis][meta.series] && 
                settings.chartFormattedData[meta.axis][meta.series][item.dataIndex] !== undefined) {
              formattedVal = settings.chartFormattedData[meta.axis][meta.series][item.dataIndex];
            } else if (meta.axis === 1 || meta.name.toLowerCase().indexOf('volume') !== -1 || meta.name.toLowerCase().indexOf('size') !== -1) {
              formattedVal = formatBytes(val);
            } else if (typeof val === 'number') {
              formattedVal = Number(val).toLocaleString();
            }
          } else if (typeof val === 'number') {
            formattedVal = Number(val).toLocaleString();
          }

          out += '<div style="display:flex;justify-content:space-between;align-items:center;gap:14px;margin:3px 0;">' +
                 '<span>' + item.marker + ' ' + item.seriesName + '</span>' +
                 '<span style="font-weight:700;font-family:monospace;">' + formattedVal + '</span>' +
                 '</div>';
        });
        return out;
      }
    },
    legend: {
      show: !isHeaderTraffic && legendData.length > 1,
      data: legendData,
      top: settings.chartTitle ? 32 : 10,
      right: 15,
      textStyle: {
        color: '#475569',
        fontSize: 11
      }
    },
    grid: {
      left: isHeaderTraffic ? 8 : 50,
      right: isHeaderTraffic ? 8 : (hasMultipleAxes ? 65 : 25),
      bottom: isHeaderTraffic ? 6 : (labels.length > 20 ? 38 : 28),
      top: isHeaderTraffic ? 8 : (settings.chartTitle ? 55 : 35),
      containLabel: true
    },
    xAxis: {
      type: 'category',
      boundaryGap: seriesList.some(function(s) { return s.type === 'bar'; }),
      data: labels,
      axisLine: {
        lineStyle: { color: '#cbd5e1' }
      },
      axisLabel: {
        color: '#64748b',
        fontSize: isHeaderTraffic ? 9 : 10.5,
        interval: isHeaderTraffic ? 'auto' : (labels.length > 24 ? 'auto' : 0),
        rotate: !isHeaderTraffic && labels.length > 24 ? 45 : 0
      }
    },
    yAxis: yAxes.length === 1 ? yAxes[0] : yAxes,
    series: seriesList
  };

  myChart.setOption(option);

  function triggerResize() {
    if (myChart && chartDom) {
      myChart.resize();
    }
  }

  setTimeout(triggerResize, 50);
  setTimeout(triggerResize, 200);
  setTimeout(triggerResize, 500);

  window.addEventListener('resize', triggerResize);
  window.addEventListener('load', triggerResize);
  document.addEventListener('DOMContentLoaded', triggerResize);
}

function hexToRgba(hex, opacity) {
  hex = hex.replace('#', '');
  if (hex.length === 3) {
    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
  }
  var r = parseInt(hex.substring(0, 2), 16) || 0;
  var g = parseInt(hex.substring(2, 4), 16) || 0;
  var b = parseInt(hex.substring(4, 6), 16) || 0;
  return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + opacity + ')';
}
